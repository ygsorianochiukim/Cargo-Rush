<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
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
        'category_id', 'truck_id', 'trip_id', 'vehicle_id', 'driver_id', 'supplier_id',
        'ledger_entry_id', 'date', 'amount_cents', 'currency',
        'payee', 'reference', 'note', 'status', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount_cents' => 'integer',
            'status' => StatusValue::class,
        ];
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
