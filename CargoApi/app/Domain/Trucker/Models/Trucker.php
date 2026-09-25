<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Support\Geo;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Support\RateBook;
use App\Domain\Trip\Models\Trip;
use Database\Factories\TruckerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An owner-operator: their own truck, hauling for the company.
 *
 * The mirror of `Driver`, and worth reading against it. A driver is an employee
 * — the operational history belongs to the record and the pay belongs to a
 * contract, a payslip and the day's ledger sheet. A trucker is a business the
 * company deals with: no contract, no payslip, no salary column, and in their
 * place a share of what each run billed, kept in the wallet.
 *
 * Two flags that a driver does not have, and they are not interchangeable:
 *
 *   `status` is what the **office** decided. Somebody who signed themselves up
 *   on a phone is `pending` until a human has looked at their licence, and goes
 *   back to `inactive` if the haulier stops working with them.
 *
 *   `is_online` is what the **partner** decided, this morning. It is the switch
 *   on their own handset, and it says nothing about whether they are trusted.
 *
 * Only somebody who is both may take work — `canTakeWork()` is the one place
 * that says so, and the job board, the accept and the office's assign all ask
 * it rather than each testing two columns their own way.
 */
class Trucker extends Model
{
    /** @use HasFactory<TruckerFactory> */
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'user_id', 'name', 'phone', 'licence_no', 'licence_expiry',
        'status', 'is_online',
        'latitude', 'longitude', 'located_at', 'trips_completed',
    ];

    protected function casts(): array
    {
        return [
            'licence_expiry' => 'date',
            'located_at' => 'datetime',
            'is_online' => 'boolean',
            'trips_completed' => 'integer',
            // Numbers, not the strings a decimal column hands back — the
            // handset puts these straight on a map, as `companies` does.
            'latitude' => 'float',
            'longitude' => 'float',
            'status' => StatusValue::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(TruckerVehicle::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function walletEntries(): HasMany
    {
        return $this->hasMany(WalletEntry::class);
    }

    /**
     * Has the office cleared this partner to haul?
     *
     * `active` and nothing else. `pending` is a registration nobody has read
     * yet and `inactive` is one somebody has stopped — both are refusals, and
     * neither is a state the partner can leave on their own.
     */
    public function isVetted(): bool
    {
        return $this->status === StatusValue::Active;
    }

    /**
     * May this partner be given a load right now?
     *
     * Vetted, switched on, and holding a truck they could actually put under
     * it. The last clause is not pedantry: a registration is a person before it
     * is a person with a unit, and a partner who has not added one yet would
     * otherwise appear on the job board and accept work with nothing to haul it
     * in.
     */
    public function canTakeWork(): bool
    {
        return $this->isVetted() && $this->is_online && $this->activeVehicle() !== null;
    }

    /**
     * The unit a run would go under.
     *
     * The first available one. Most partners have exactly one truck, and the
     * ones with several are telling us which are in the shop by setting
     * `maintenance` on them — so "the first that is not" is the whole of the
     * choice, and a screen asking a man with one truck which truck he means
     * would be a screen nobody thanks you for.
     */
    public function activeVehicle(): ?TruckerVehicle
    {
        return $this->vehicles
            ->first(static fn (TruckerVehicle $vehicle): bool => $vehicle->status === StatusValue::Available);
    }

    /**
     * What this partner's runs are charged at, in basis points.
     *
     * The haulier's standing rate — one figure for everybody it hauls with,
     * set on the settings card and twelve per cent until somebody moves it.
     *
     * There was a per-partner override here, and it is gone. It could only be
     * set from a number field on the partner's own detail screen, beside an
     * approve button and a wallet, which is not where a commercial term
     * belongs; and a rate that could be anything per partner is a rate nobody
     * can state. Nothing already earned moved when it went: every settled run
     * froze its own rate onto `trips.commission_bp` at delivery.
     */
    public function commissionRateBp(): int
    {
        return app(RateBook::class)->for($this->company)->truckerCommissionBp();
    }

    /** Is the pin on this partner recent enough to sort a job board by? */
    public function hasFreshPosition(int $withinMinutes = 120): bool
    {
        return $this->latitude !== null
            && $this->longitude !== null
            && $this->located_at !== null
            && $this->located_at->isAfter(now()->subMinutes($withinMinutes));
    }

    /**
     * Straight-line metres from this partner's last pin to a point.
     *
     * Null when there is no usable pin — which the job board reads as "sort
     * them last" rather than as zero, since zero would put somebody who has
     * never reported a position at the top of every list.
     */
    public function metresTo(?float $lat, ?float $lng): ?float
    {
        if ($lat === null || $lng === null || ! $this->hasFreshPosition()) {
            return null;
        }

        return Geo::metresBetween((float) $this->latitude, (float) $this->longitude, $lat, $lng);
    }

    /** Partners the office has cleared and who have their switch on. */
    public function scopeTakingWork(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value)->where('is_online', true);
    }
}
