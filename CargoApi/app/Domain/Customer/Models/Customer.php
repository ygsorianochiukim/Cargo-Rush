<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VatTreatment;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
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
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'name', 'contact', 'address', 'latitude', 'longitude', 'rating', 'status',
        'tin', 'vat_treatment', 'withholds_tax', 'withholding_rate_bp',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'latitude' => 'float',
            'longitude' => 'float',
            'status' => StatusValue::class,
            'vat_treatment' => VatTreatment::class,
            'withholds_tax' => 'boolean',
            'withholding_rate_bp' => 'integer',
        ];
    }

    public function isPinned(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function vatTreatment(): VatTreatment
    {
        return $this->vat_treatment ?? VatTreatment::Vatable;
    }

    public function withholdsTax(): bool
    {
        return (bool) $this->withholds_tax;
    }

    /** @var array{email: string, password: string}|null */
    public ?array $newLogin = null;

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

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function outstandingCents(): int
    {
        return $this->invoices()
            ->where('direction', InvoiceDirection::Receivable->value)
            ->where('status', '!=', StatusValue::Cancelled->value)
            ->withSum('allocations', 'amount_cents')
            ->get()
            ->sum(static fn (Invoice $invoice): int => $invoice->balanceCents());
    }

    public function paidCents(): int
    {
        return (int) PaymentAllocation::query()
            ->whereHas(
                'invoice',
                fn ($query) => $query
                    ->where('customer_id', $this->getKey())
                    ->where('direction', InvoiceDirection::Receivable->value),
            )
            ->sum('amount_cents');
    }
}
