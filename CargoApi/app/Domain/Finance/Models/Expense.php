<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One categorised outgoing.
 *
 * A truck of null is not a missing value — it is fleet overhead, the office
 * rent and the annual permits, which belong in a period's total without being
 * charged to any one unit's profitability. Every roll-up has to decide what to
 * do with those, and `scopeOverhead` is what makes the decision visible rather
 * than an accidental property of a `groupBy`.
 */
class Expense extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'category_id', 'truck_id', 'trip_id', 'vehicle_id', 'driver_id',
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

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Spend that actually counts.
     *
     * A pending claim has not been approved and a cancelled one was refused.
     * Counting either would put money against a quarter that the business
     * never paid out, so every roll-up goes through this and none of them
     * re-states the rule.
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }

    /** Fleet overhead: real spend that belongs to no single unit. */
    public function scopeOverhead(Builder $query): Builder
    {
        return $query->whereNull('truck_id');
    }
}
