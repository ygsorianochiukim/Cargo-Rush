<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A receivable (money in) or a payable (money out). */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'number', 'customer_id', 'payee', 'issued_at', 'due_at',
        'amount_cents', 'currency', 'direction', 'status',
        'trip_id', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'due_at' => 'date',
            'amount_cents' => 'integer',
            'paid_at' => 'datetime',
            'direction' => InvoiceDirection::class,
            'status' => StatusValue::class,
        ];
    }

    /**
     * An invoice is never inserted without its number.
     *
     * Same reasoning as a trip's reference: assigning it in a service leaves a
     * window where a row exists with no number, which the column forbids and
     * which would be a document nobody can quote in a payment. Doing it here
     * means no code path — controller, seeder, factory or console — can skip
     * it.
     */
    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            $invoice->number ??= static::nextNumber(
                $invoice->direction ?? InvoiceDirection::Receivable,
            );
        });
    }

    /**
     * The next number in the series for a direction.
     *
     * Two series, because the workbook keeps two: money owed to us is
     * `INV-{year}-####` and money we owe is `BILL-{year}-####`. Mixing them
     * would make a receivable and a payable share a run of numbers, and the
     * two are reconciled against different people.
     *
     * Scoped to the year, so the count restarts each January exactly as the
     * printed series does.
     */
    public static function nextNumber(InvoiceDirection $direction, ?int $year = null): string
    {
        $year ??= (int) now()->year;
        $stem = ($direction === InvoiceDirection::Payable ? 'BILL' : 'INV')."-{$year}-";

        // `withTrashed`, because the unique index still holds a soft-deleted
        // row's number — reissuing it would collide on insert.
        //
        // Ordered by length first: the suffix is padded to four, so past 9999
        // a plain string sort puts `-10000` before `-9999` and the series
        // would start handing out numbers it has already used.
        $last = static::withTrashed()
            ->where('number', 'like', $stem.'%')
            ->orderByRaw('LENGTH(number) DESC')
            ->orderByDesc('number')
            ->value('number');

        $next = $last === null ? 1 : (int) substr((string) $last, strlen($stem)) + 1;

        return $stem.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The haul this document is for, when there is one.
     *
     * Null on an invoice somebody raised by hand — a monthly retainer, a
     * payable to a supplier — which is most of what the Billing form is for.
     * Set on every receivable the system raises itself when a run is
     * delivered, which is what lets Billing print the trip reference beside
     * the amount instead of leaving the reconciliation to a human.
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** Settled. Money in, not merely billed. */
    public function isPaid(): bool
    {
        return $this->status === StatusValue::Paid;
    }

    /** Who the document is addressed to, whichever direction it points. */
    public function counterparty(): string
    {
        return $this->customer?->name ?? $this->payee ?? 'Unknown';
    }

    /** Unpaid and past its due date. Derived, so it cannot go stale. */
    public function isOverdue(): bool
    {
        return $this->status === StatusValue::Pending && $this->due_at->isPast();
    }
}
