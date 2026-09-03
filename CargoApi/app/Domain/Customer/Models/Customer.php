<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = ['name', 'contact', 'rating', 'status'];

    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'status' => StatusValue::class,
        ];
    }

    /**
     * Credentials just created for this firm, on the instance that created
     * them — never read from the database, because a password hash is not
     * something to read back.
     *
     * The create response is the one chance the office has to see the starting
     * password and pass it on, so `CustomerResource` prints it from here and
     * nowhere else. Null on every other instance, which is every read.
     *
     * @var array{email: string, password: string}|null
     */
    public ?array $newLogin = null;

    /**
     * The accounts that sign in for this firm.
     *
     * Plural on purpose: two people at the same company can each have a login
     * and see the same deliveries and the same invoices, because the history
     * belongs to the firm and not to whoever is holding the phone.
     */
    public function logins(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * The days of income and expenses recorded against this customer's work.
     *
     * A trip says the haul happened and an invoice says it was billed; this is
     * what it actually earned and cost. History showed the first two and never
     * the third, so a customer could have a month of hauling behind them and
     * no money anywhere on the record.
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * What they still owe, in centavos. Derived from unsettled receivables, so
     * it can never drift from the Billing module.
     */
    public function outstandingCents(): int
    {
        return (int) $this->invoices()
            ->where('direction', InvoiceDirection::Receivable->value)
            ->whereIn('status', [StatusValue::Pending->value, StatusValue::Overdue->value])
            ->sum('amount_cents');
    }
}
