<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Models;

use App\Domain\Driver\Models\Driver;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
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
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'plate', 'model', 'registration_no', 'capacity_kg', 'status',
        'driver_id', 'odometer_km', 'next_service_km',
    ];

    protected function casts(): array
    {
        return [
            'capacity_kg' => 'integer',
            'odometer_km' => 'integer',
            'next_service_km' => 'integer',
            'status' => StatusValue::class,
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
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
