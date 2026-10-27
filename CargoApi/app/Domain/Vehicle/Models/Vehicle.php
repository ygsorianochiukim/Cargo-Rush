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
        'driver_id', 'truck_category_id', 'truck_size_id', 'odometer_km', 'next_service_km',
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

    public function ownerPartner(): BelongsTo
    {
        return $this->belongsTo(Trucker::class, 'owner_trucker_id');
    }

    public function terms(): VehicleArrangement
    {
        return $this->arrangement ?? VehicleArrangement::Owned;
    }

    public function sharesRevenue(): bool
    {
        return $this->terms()->sharesRevenue()
            && $this->owner_trucker_id !== null
            && $this->shareRateBp() > 0;
    }

    public function shareRateBp(): int
    {
        return $this->share_bp ?? $this->terms()->defaultShareBp() ?? 0;
    }

    public function chargesRent(): bool
    {
        return $this->terms()->chargesRent() && (int) $this->rent_cents > 0;
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function driverAssignments(): HasMany
    {
        return $this->hasMany(VehicleDriverAssignment::class);
    }

    public function fuelRecords(): HasMany
    {
        return $this->hasMany(FuelRecord::class);
    }

    public function maintenanceJobs(): HasMany
    {
        return $this->hasMany(MaintenanceJob::class);
    }

    public function kmToService(): int
    {
        return $this->next_service_km - $this->odometer_km;
    }
}
