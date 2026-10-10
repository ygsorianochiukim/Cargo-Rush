<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Finance\Models\Truck;
use App\Domain\Shared\Enums\BalanceSide;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'journal_entry_id', 'account_id', 'line_no',
        'debit_cents', 'credit_cents', 'memo',
        'truck_id', 'trip_id', 'customer_id',
    ];

    protected function casts(): array
    {
        return [
            'debit_cents' => 'integer',
            'credit_cents' => 'integer',
            'line_no' => 'integer',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function side(): BalanceSide
    {
        return $this->debit_cents !== 0 ? BalanceSide::Debit : BalanceSide::Credit;
    }

    public function amountCents(): int
    {
        return $this->debit_cents !== 0 ? $this->debit_cents : $this->credit_cents;
    }

    public function effectOnAccount(): int
    {
        $normal = $this->account->normalBalance();

        return $normal->sign(BalanceSide::Debit) * $this->debit_cents
            + $normal->sign(BalanceSide::Credit) * $this->credit_cents;
    }
}
