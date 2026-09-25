<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Models;

use App\Domain\Driver\Models\Driver;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'plate', 'model', 'registration_no', 'capacity_kg', 'status',
        'driver_id', 'truck_category_id', 'odometer_km', 'next_service_km',
        // On what terms this truck runs for the fleet, and who is paid for it.
        // Every unit dispatches identically; these decide only where the money
        // goes when a run closes. See `VehicleArrangement`.
        'arrangement', 'wheels', 'owner_name', 'owner_contact',
        'rent_cents', 'share_bp', 'owner_trucker_id',
    ];

    protected function casts(): array
    {
        return [
            'capacity_kg' => 'integer',
            'odometer_km' => 'integer',
            'next_service_km' => 'integer',
            'wheels' => 'integer',
            'rent_cents' => 'integer',
            'share_bp' => 'integer',
            'status' => StatusValue::class,
            'arrangement' => VehicleArrangement::class,
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * Whoever is owed a share of what this truck earns.
     *
     * A `truckers` row, because the debt behaves exactly like a partner's and
     * that account already exists: it accrues per delivered run, nets against
     * what they owe back, and is settled a run at a time with a reference. For
     * a sub-contractor it is the operator, with the handset; for a rented
     * ten-wheeler it is the owner, who may have no login and no licence.
     *
     * Null on an owned or flat-rented truck, which owes nobody a share.
     */
    public function ownerPartner(): BelongsTo
    {
        return $this->belongsTo(Trucker::class, 'owner_trucker_id');
    }

    /** How this truck is paid for. `owned` for anything that never said. */
    public function terms(): VehicleArrangement
    {
        return $this->arrangement ?? VehicleArrangement::Owned;
    }

    /**
     * Does a delivery on this truck owe somebody outside the fleet a share?
     *
     * The question `TripService::putOnTheBooks()` asks, and it needs both
     * halves to be true: the arrangement has to be a share *and* somebody has
     * to be named to receive it. A truck set to `rented_share` with no partner
     * and no rate is half-configured, and the honest answer is that nothing is
     * owed to nobody — the office can see the gap on the fleet screen.
     */
    public function sharesRevenue(): bool
    {
        return $this->terms()->sharesRevenue()
            && $this->owner_trucker_id !== null
            && $this->shareRateBp() > 0;
    }

    /**
     * The fleet's cut of a run on this truck, in basis points.
     *
     * The rate negotiated for this unit, falling back to what the arrangement
     * usually pays — 15% on a rented ten-wheeler, 12% on a sub-contractor.
     */
    public function shareRateBp(): int
    {
        return $this->share_bp ?? $this->terms()->defaultShareBp() ?? 0;
    }

    /** Is a monthly rent due on this truck whether it turns a wheel or not? */
    public function chargesRent(): bool
    {
        return $this->terms()->chargesRent() && (int) $this->rent_cents > 0;
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function fuelRecords(): HasMany
    {
        return $this->hasMany(FuelRecord::class);
    }

    public function maintenanceJobs(): HasMany
    {
        return $this->hasMany(MaintenanceJob::class);
    }

    /** Distance left before the scheduled service. Negative means overdue. */
    public function kmToService(): int
    {
        return $this->next_service_km - $this->odometer_km;
    }
}
