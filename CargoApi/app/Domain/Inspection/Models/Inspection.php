<?php

declare(strict_types=1);

namespace App\Domain\Inspection\Models;

use App\Domain\Driver\Models\Driver;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\TruckerDriver;
use App\Domain\Trucker\Models\TruckerVehicle;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Factories\InspectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A completed pre-trip check. Mobile-only capture (DESIGN.md section 5.4) —
 * the web app reads these but never writes one.
 */
class Inspection extends Model
{
    /** @use HasFactory<InspectionFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

    protected $fillable = [
        'trip_id', 'vehicle_id', 'driver_id', 'results',
        // A trucker's truck and driver — never set together with the two
        // fleet columns above. See the migration that added them.
        'trucker_vehicle_id', 'trucker_driver_id',
        'good_to_go', 'notes', 'inspected_at',
    ];

    protected function casts(): array
    {
        return [
            'results' => 'array',
            'good_to_go' => 'boolean',
            'inspected_at' => 'datetime',
        ];
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

    public function truckerVehicle(): BelongsTo
    {
        return $this->belongsTo(TruckerVehicle::class);
    }

    public function truckerDriver(): BelongsTo
    {
        return $this->belongsTo(TruckerDriver::class);
    }

    /** Whoever did the check — a Cargo Rush driver or a trucker's. */
    public function inspectorName(): ?string
    {
        return $this->driver?->name ?? $this->truckerDriver?->name;
    }

    /** Which checklist keys came back failed. */
    public function failures(): array
    {
        return array_keys(array_filter($this->results ?? [], static fn ($ok) => $ok === false));
    }
}
