<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Models;

use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use Database\Factories\TruckerVehicleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A partner's own truck.
 *
 * Deliberately not a `vehicles` row. The fleet table carries an odometer, a
 * service interval and a fuel budget, and the dashboard counts its rows to say
 * how much of the fleet is working — a truck the haulier neither owns nor
 * maintains would inflate that figure, put somebody else's service schedule on
 * the workshop's list, and open a daily profitability sheet for costs that are
 * none of the company's business.
 *
 * What the desk actually needs to know about a partner's truck is what it can
 * carry and whether it is running today. That is the whole of this table.
 */
class TruckerVehicle extends Model
{
    /** @use HasFactory<TruckerVehicleFactory> */
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'trucker_id', 'plate', 'model', 'capacity_kg', 'truck_category_id', 'status',
    ];

    protected function casts(): array
    {
        return [
            'capacity_kg' => 'integer',
            'status' => StatusValue::class,
        ];
    }

    public function trucker(): BelongsTo
    {
        return $this->belongsTo(Trucker::class);
    }

    /**
     * The kind of unit, against the catalogue the rate card prices from.
     *
     * Shared with the fleet on purpose: a freezer is a freezer whoever owns it,
     * and a load that asks for one has to be matchable against both a company
     * truck and a partner's without the two sides meaning different things by
     * the word.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TruckCategory::class, 'truck_category_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /**
     * Could this unit take that load?
     *
     * Weight, and the category when the load names one. A null weight or a null
     * category on the trip means the office did not say, and an unstated
     * requirement is not a requirement — refusing on it would empty the job
     * board for every run somebody booked in a hurry.
     */
    public function canCarry(?int $weightKg, ?string $categoryId): bool
    {
        if ($this->status !== StatusValue::Available) {
            return false;
        }

        if ($weightKg !== null && $weightKg > $this->capacity_kg) {
            return false;
        }

        return $categoryId === null
            || $this->truck_category_id === null
            || $this->truck_category_id === $categoryId;
    }
}
