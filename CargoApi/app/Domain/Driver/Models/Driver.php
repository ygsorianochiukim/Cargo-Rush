<?php

declare(strict_types=1);

namespace App\Domain\Driver\Models;

use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Incident\Models\Incident;
use App\Domain\Shared\Enums\CrewEmployment;
use App\Domain\Shared\Enums\CrewRole;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Factories\DriverFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Driver extends Model
{
    /** @use HasFactory<DriverFactory> */
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'user_id', 'role', 'employment', 'name', 'contact', 'licence_no', 'licence_expiry', 'violations',
        'status', 'trips_completed', 'on_time_rate',
    ];

    protected $attributes = [
        'role' => 'driver',
        'employment' => 'company',
    ];

    protected function casts(): array
    {
        return [
            'role' => CrewRole::class,
            'employment' => CrewEmployment::class,
            'licence_expiry' => 'date',
            'violations' => 'integer',
            'trips_completed' => 'integer',
            'on_time_rate' => 'float',
            'status' => StatusValue::class,
        ];
    }

    public function isHelper(): bool
    {
        return $this->role === CrewRole::Helper;
    }

    public function employment(): CrewEmployment
    {
        return $this->employment ?? CrewEmployment::Company;
    }

    public function isOnPayroll(): bool
    {
        return $this->employment()->onPayroll();
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function helperTrips(): BelongsToMany
    {
        return $this->belongsToMany(Trip::class, 'trip_helpers')->withTimestamps();
    }

    public function fuelRecords(): HasMany
    {
        return $this->hasMany(FuelRecord::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function licenceExpiresWithin(int $days = 60): bool
    {
        return $this->licence_expiry !== null
            && $this->licence_expiry->isBefore(now()->addDays($days));
    }
}
