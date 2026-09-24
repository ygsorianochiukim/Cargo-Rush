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

/**
 * The centre of the operations side. GPS, dispatch, delivery and incidents all
 * hang off a trip; the reference is the only id a human reads.
 */
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
        // The partner half. `booking_source` is fillable because the desk
        // assigning a run writes it; the two commission columns are not, and
        // are force-filled once at delivery — see `Trip::isBilled()` for the
        // rule about what may only happen once.
        'trucker_id', 'trucker_vehicle_id', 'booking_source',
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

    /**
     * Who rode on the run to load and unload it, in the order the desk named
     * them. Any number, including none — see the migration that replaced the
     * single `helper_id` for why one was never enough.
     */
    public function helpers(): BelongsToMany
    {
        return $this->belongsToMany(Driver::class, 'trip_helpers')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * Put these people on the run as its helpers, in this order.
     *
     * A sync rather than an attach, so naming the crew again replaces it: the
     * desk editing a trip is stating who is on it, not adding to a list.
     *
     * @param  string[]  $driverIds
     */
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

    /**
     * The partner hauling this, when it is not the haulier's own crew.
     *
     * A run has a driver or a trucker, never both: one is an employee in a
     * company truck and the other is an owner-operator in their own, and a row
     * naming both would be two people claiming the same load. Nothing in the
     * schema forbids it — a foreign key cannot express "exactly one of these
     * two" — so `hauledByPartner()` is what the rest of the system asks rather
     * than testing columns itself.
     */
    public function trucker(): BelongsTo
    {
        return $this->belongsTo(Trucker::class);
    }

    public function truckerVehicle(): BelongsTo
    {
        return $this->belongsTo(TruckerVehicle::class, 'trucker_vehicle_id');
    }

    /**
     * The kind of unit this load asks for, when it asks for one.
     *
     * Already on the row — the rate card prices against it — and given a
     * relation here because the job board has to say "freezer" on a card rather
     * than a ULID.
     */
    public function truckCategory(): BelongsTo
    {
        return $this->belongsTo(TruckCategory::class, 'truck_category_id');
    }

    /** Is a partner carrying this rather than the company's own crew? */
    public function hauledByPartner(): bool
    {
        return $this->trucker_id !== null;
    }

    /**
     * Does the haulier collect this run's money?
     *
     * Yes for everything it booked itself, including a partner run the desk
     * assigned; no for one a customer gave straight to a partner, where the money
     * is between them and the customer. The invoice step reads this and raises
     * nothing when it is false — billing a customer for a haul somebody has
     * already been paid for would charge them twice.
     */
    public function collectedByCarrier(): bool
    {
        return ($this->booking_source ?? BookingSource::CargoRush)->collectedByCarrier();
    }

    public function pings(): HasMany
    {
        return $this->hasMany(GpsPing::class);
    }

    /** The position the GPS Dashboard draws. */
    public function latestPing(): HasOne
    {
        return $this->hasOne(GpsPing::class)->latestOfMany('recorded_at');
    }

    /**
     * The pre-trip check that cleared this run — or the last attempt at one.
     *
     * A run cannot be started without a passing check (see
     * `TripService::startForDriver`), so on anything in transit or delivered
     * this is the record of the truck being looked over before it rolled. On a
     * confirmed run it is null until the driver does it, or it is a failed
     * attempt sitting there with the reason on it.
     *
     * The *latest* of them, because a held unit gets checked again once the
     * fault is fixed and what matters is where it stands now.
     *
     * The id is the tiebreak, and it is not decoration: a fault found and fixed
     * inside the same second — which is every test and the occasional real
     * re-check at the gate — leaves two rows with identical timestamps, and
     * "whichever the database returns first" would sometimes be the failed one.
     * A ULID sorts by the moment it was minted, so the highest is the newest.
     */
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

    /** Was this person on the run — driving it, or as a helper on it? */
    public function isCrewedBy(?string $driverId): bool
    {
        return $driverId !== null
            && ($this->driver_id === $driverId || $this->helpers()->whereKey($driverId)->exists());
    }

    /**
     * The receivable this haul raised, once it was delivered.
     *
     * A trip has at most one, and it is created by the delivery rather than by
     * anybody filling in the billing form — which is what makes `billed_at`
     * and this relation two views of the same fact.
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * Has this run already been put on the books?
     *
     * The one guard that keeps the money honest. Delivering credits the day's
     * ledger row and raises the customer's invoice, and both are additive —
     * so a second hand-off (a re-press on a bad signal, or the office closing
     * a run the driver already closed) would charge for the haul twice. This
     * is what makes that impossible without either side having to know about
     * the other.
     */
    public function isBilled(): bool
    {
        return $this->billed_at !== null;
    }

    /** The statuses that mean a unit is out on the road right now. */
    public function scopeOnTheRoad(Builder $query): Builder
    {
        return $query->whereIn('status', [
            StatusValue::InTransit->value,
            StatusValue::Assigned->value,
            StatusValue::Overdue->value,
        ]);
    }

    /**
     * Late is a derived fact, not a column: past its ETA and not yet closed.
     * Storing it would need a cron to stay true.
     */
    public function isLate(): bool
    {
        return $this->eta !== null
            && $this->eta->isPast()
            && ! in_array($this->status, [StatusValue::Delivered, StatusValue::Cancelled], true);
    }

    /**
     * A trip is never inserted without its reference.
     *
     * Assigning it in a service left a window where a row existed with a null
     * reference — which the column forbids, and which would be a trip a human
     * could not name. Doing it here means no code path can skip it.
     */
    protected static function booted(): void
    {
        static::creating(function (self $trip): void {
            $trip->reference ??= static::nextReference();
        });
    }

    /** Has this trip been pinned at both ends? */
    public function isMapped(): bool
    {
        return $this->origin_lat !== null
            && $this->origin_lng !== null
            && $this->destination_lat !== null
            && $this->destination_lng !== null;
    }

    /**
     * Great-circle distance between the two points, in metres.
     *
     * Straight-line, and **not** what prices a trip — a truck drives the road,
     * which in Northern Mindanao is 1.15 to 1.8 times longer. `RoadDistance`
     * measures the run for the quote; this is what it falls back on.
     */
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

    /** The next reference in the CR-##### series. */
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
