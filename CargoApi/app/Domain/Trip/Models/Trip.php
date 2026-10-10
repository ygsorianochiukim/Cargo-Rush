<?php

declare(strict_types=1);

namespace App\Domain\Trip\Models;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Dispatch\Models\DispatchRecord;
use App\Domain\Driver\Models\Driver;
use App\Domain\Gps\Models\GpsPing;
use App\Domain\Inspection\Models\Inspection;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Shared\Enums\BookingSource;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Support\Geo;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerDriver;
use App\Domain\Trucker\Models\TruckerVehicle;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'reference', 'customer_id', 'origin', 'destination', 'cargo',
        'origin_lat', 'origin_lng', 'destination_lat', 'destination_lng',
        'weight_kg', 'pieces', 'handling', 'driver_id',
        'vehicle_id', 'truck_category_id', 'status', 'pickup_place', 'dropoff_place',
        'scheduled_at', 'eta', 'distance_total_m',
        'price_cents', 'currency', 'billed_at', 'requested_by',
        'pricing_zone_id', 'pricing_bracket_id', 'fuel_adjustment_bp', 'fuel_surcharge_cents',
        'pricing_source', 'pricing_note',
        'trucker_id', 'trucker_vehicle_id', 'trucker_driver_id', 'booking_source',
    ];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'integer',
            'origin_lat' => 'float',
            'origin_lng' => 'float',
            'destination_lat' => 'float',
            'destination_lng' => 'float',
            'pieces' => 'integer',
            'distance_total_m' => 'integer',
            'price_cents' => 'integer',
            'fuel_adjustment_bp' => 'integer',
            'fuel_surcharge_cents' => 'integer',
            'billed_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'dispatch_checklist' => 'array',
            'dispatch_checked_at' => 'datetime',
            'eta' => 'datetime',
            'status' => StatusValue::class,
            'booking_source' => BookingSource::class,
            'commission_bp' => 'integer',
            'commission_cents' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function helpers(): BelongsToMany
    {
        return $this->belongsToMany(Driver::class, 'trip_helpers')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /** @param string[] $driverIds */
    public function setHelpers(array $driverIds): void
    {
        $this->helpers()->sync(collect(array_values(array_unique($driverIds)))
            ->mapWithKeys(static fn (string $id, int $position): array => [$id => ['position' => $position]])
            ->all());

        $this->unsetRelation('helpers');
    }

    /** @return string[] */
    public function helperIds(): array
    {
        return $this->helpers->pluck('id')->all();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function trucker(): BelongsTo
    {
        return $this->belongsTo(Trucker::class);
    }

    public function truckerDriver(): BelongsTo
    {
        return $this->belongsTo(TruckerDriver::class)->withTrashed();
    }

    public function truckerVehicle(): BelongsTo
    {
        return $this->belongsTo(TruckerVehicle::class, 'trucker_vehicle_id');
    }

    public function truckCategory(): BelongsTo
    {
        return $this->belongsTo(TruckCategory::class, 'truck_category_id');
    }

    public function hauledByPartner(): bool
    {
        return $this->trucker_id !== null;
    }

    public function collectedByCarrier(): bool
    {
        return ($this->booking_source ?? BookingSource::CargoRush)->collectedByCarrier();
    }

    public function pings(): HasMany
    {
        return $this->hasMany(GpsPing::class);
    }

    public function latestPing(): HasOne
    {
        return $this->hasOne(GpsPing::class)->latestOfMany('recorded_at');
    }

    public function latestInspection(): HasOne
    {
        return $this->hasOne(Inspection::class)->ofMany([
            'inspected_at' => 'max',
            'id' => 'max',
        ]);
    }

    public function dispatchRecord(): HasOne
    {
        return $this->hasOne(DispatchRecord::class);
    }

    public function deliveryLog(): HasOne
    {
        return $this->hasOne(DeliveryLog::class);
    }

    public function isCrewedBy(?string $driverId): bool
    {
        return $driverId !== null
            && ($this->driver_id === $driverId || $this->helpers()->whereKey($driverId)->exists());
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function isBilled(): bool
    {
        return $this->billed_at !== null;
    }

    public function isPriced(): bool
    {
        return $this->price_cents !== null;
    }

    public function isManuallyPriced(): bool
    {
        return $this->pricing_source === 'manual';
    }

    public function scopeOnTheRoad(Builder $query): Builder
    {
        return $query->whereIn('status', [
            StatusValue::InTransit->value,
            StatusValue::Assigned->value,
            StatusValue::Overdue->value,
        ]);
    }

    public function isLate(): bool
    {
        return $this->eta !== null
            && $this->eta->isPast()
            && ! in_array($this->status, [StatusValue::Delivered, StatusValue::Cancelled], true);
    }

    protected static function booted(): void
    {
        static::creating(function (self $trip): void {
            $trip->reference ??= static::nextReference();
        });
    }

    public function isMapped(): bool
    {
        return $this->origin_lat !== null
            && $this->origin_lng !== null
            && $this->destination_lat !== null
            && $this->destination_lng !== null;
    }

    public function straightLineDistanceM(): ?int
    {
        if (! $this->isMapped()) {
            return null;
        }

        return (int) round(Geo::metresBetween(
            (float) $this->origin_lat,
            (float) $this->origin_lng,
            (float) $this->destination_lat,
            (float) $this->destination_lng,
        ));
    }

    public static function nextReference(): string
    {
        $last = static::withTrashed()
            ->where('reference', 'like', 'CR-%')
            ->orderByDesc('reference')
            ->value('reference');

        $n = $last === null ? 24800 : (int) substr((string) $last, 3);

        return 'CR-'.($n + 1);
    }
}
