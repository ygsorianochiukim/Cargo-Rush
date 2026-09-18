<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A kind of unit — Dry Goods, Freezer, Flatbed.
 *
 * What a job needs and what the fleet can offer, and the second dimension of
 * the rate card. Reefer work carries a premium that has nothing to do with how
 * far the run is, and a card that could only price distance forced the desk to
 * quote that premium off-system and type the figure in by hand.
 *
 * Per company, like `positions`: what a haulier calls its unit types, and what
 * it charges for them, is nobody else's business.
 *
 * ## Three things point at it, for three different reasons
 *
 * A **bracket** may name one, and that is pricing: "freezer, 0–50 km".
 *
 * A **trip** names one at booking, and that is a *requirement* — the customer
 * asked for a freezer, and the quote follows what they asked for rather than
 * whatever unit the yard assigns three days later.
 *
 * A **vehicle** names one, and that is a fact about the fleet. It prices
 * nothing; it is what lets dispatch put a freezer job on a freezer truck.
 */
class TruckCategory extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = ['key', 'name', 'description', 'position', 'status'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'status' => StatusValue::class,
        ];
    }

    /** The rate-card lines priced for this kind of unit. */
    public function brackets(): HasMany
    {
        return $this->hasMany(PricingBracket::class);
    }

    /** The units in the fleet that are one. */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function isActive(): bool
    {
        return $this->status === StatusValue::Active;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }

    public function scopeInCardOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('name');
    }
}
