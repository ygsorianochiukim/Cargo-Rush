<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VatTreatment;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Support\RateBook;
use App\Domain\Trip\Models\Trip;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'number', 'customer_id', 'payee', 'issued_at', 'due_at',
        'amount_cents', 'currency', 'direction', 'status',
        'trip_id', 'paid_at',
        'net_amount_cents', 'vat_cents', 'withholding_cents',
        'vat_rate_bp', 'withholding_rate_bp', 'vat_treatment',
        'supplier_id',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'due_at' => 'date',
            'amount_cents' => 'integer',
            'net_amount_cents' => 'integer',
            'vat_cents' => 'integer',
            'withholding_cents' => 'integer',
            'vat_rate_bp' => 'integer',
            'withholding_rate_bp' => 'integer',
            'vat_treatment' => VatTreatment::class,
            'paid_at' => 'datetime',
            'direction' => InvoiceDirection::class,
            'status' => StatusValue::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            $invoice->number ??= static::nextNumber(
                $invoice->direction ?? InvoiceDirection::Receivable,
            );

            $invoice->net_amount_cents ??= (int) $invoice->amount_cents - (int) $invoice->vat_cents;
        });
    }

    public static function nextNumber(InvoiceDirection $direction, ?int $year = null): string
    {
        $year ??= (int) now()->year;
        $stem = ($direction === InvoiceDirection::Payable ? 'BILL' : 'INV')."-{$year}-";

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

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'payment_allocations')
            ->withPivot('amount_cents')
            ->withTimestamps();
    }

    public function dueCents(): int
    {
        return max(0, $this->amount_cents - $this->withholding_cents);
    }

    public function paidCents(): int
    {
        return (int) ($this->allocations_sum_amount_cents ?? $this->allocations()->sum('amount_cents'));
    }

    public function balanceCents(): int
    {
        return max(0, $this->dueCents() - $this->paidCents());
    }

    public function taxBaseCents(): int
    {
        return app(RateBook::class)->pricesIncludeVat()
            ? (int) $this->amount_cents
            : (int) $this->net_amount_cents;
    }

    public function isPaid(): bool
    {
        return $this->status === StatusValue::Paid;
    }

    public function isPartlyPaid(): bool
    {
        $paid = $this->paidCents();

        return $paid > 0 && $paid < $this->dueCents();
    }

    public function statusFromPayments(): StatusValue
    {
        if ($this->balanceCents() <= 0) {
            return StatusValue::Paid;
        }

        if ($this->isPartlyPaid()) {
            return StatusValue::Partial;
        }

        return $this->due_at->lt(today()) ? StatusValue::Overdue : StatusValue::Pending;
    }

    public function counterparty(): string
    {
        return $this->customer?->name ?? $this->payee ?? 'Unknown';
    }

    public function isOverdue(): bool
    {
        return $this->status === StatusValue::Pending && $this->due_at->isPast();
    }
}
