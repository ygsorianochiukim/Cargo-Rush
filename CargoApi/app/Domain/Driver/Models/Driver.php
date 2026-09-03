<?php

declare(strict_types=1);

namespace App\Domain\Driver\Models;

use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Identity\Models\User;
use App\Domain\Incident\Models\Incident;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person who drives, or rides along as a helper. Both are the same record —
 * the difference is which column of a trip they sit in.
 */
class Driver extends Model
{
    /** @use HasFactory<DriverFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'user_id', 'name', 'licence_no', 'licence_expiry', 'violations',
        'status', 'trips_completed', 'on_time_rate',
    ];

    protected function casts(): array
    {
        return [
            'licence_expiry' => 'date',
            'violations' => 'integer',
            'trips_completed' => 'integer',
            'on_time_rate' => 'float',
            'status' => StatusValue::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The unit they currently hold the keys to, if any. */
    public function vehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /** Trips they rode as the helper rather than the driver. */
    public function helperTrips(): HasMany
    {
        return $this->hasMany(Trip::class, 'helper_id');
    }

    public function fuelRecords(): HasMany
    {
        return $this->hasMany(FuelRecord::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * A licence inside the warning window is the one thing the Drivers module
     * flags before anything has actually gone wrong.
     */
    public function licenceExpiresWithin(int $days = 60): bool
    {
        return $this->licence_expiry !== null
            && $this->licence_expiry->isBefore(now()->addDays($days));
    }
}
