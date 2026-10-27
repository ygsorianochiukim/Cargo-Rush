<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Accounting\Models\PaymentSource;
use App\Domain\Asset\Models\Asset;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\PaySchedule;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'category_id', 'truck_id', 'trip_id', 'vehicle_id', 'driver_id', 'supplier_id', 'asset_id',
        'payment_source_id', 'pay_schedule', 'ledger_entry_id', 'date', 'amount_cents', 'currency', 'is_vatable',
        'payee', 'reference', 'note', 'status', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount_cents' => 'integer',
            'is_vatable' => 'boolean',
            'pay_schedule' => PaySchedule::class,
            'status' => StatusValue::class,
        ];
    }

    public function vatCents(int $rateBp): int
    {
        if (! $this->is_vatable || $rateBp <= 0) {
            return 0;
        }

        $amount = (int) $this->amount_cents;

        return $amount - intdiv($amount * 10_000 + intdiv(10_000 + $rateBp, 2), 10_000 + $rateBp);
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class)->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }

    public function scopeOverhead(Builder $query): Builder
    {
        return $query->whereNull('truck_id');
    }
}
