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
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Support\Geo;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'weight_kg', 'pieces', 'handling', 'driver_id', 'helper_id',
        'vehicle_id', 'truck_category_id', 'status', 'pickup_place', 'dropoff_place',
        'scheduled_at', 'eta', 'distance_total_m',
        'price_cents', 'currency', 'billed_at', 'requested_by',
        'pricing_zone_id', 'pricing_bracket_id', 'fuel_adjustment_bp', 'fuel_surcharge_cents',
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

    public function helper(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'helper_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
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
     * Straight-line, not road distance — a road network is a routing service
     * this system does not have. It is a floor on the real distance and a
     * sane default for a trip nobody has measured, which beats the zero it
     * would otherwise carry. A dispatcher can still overwrite it.
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
