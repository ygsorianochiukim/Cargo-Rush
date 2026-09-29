<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Repositories\PricingZoneRepository;
use Illuminate\Support\Collection;

/**
 * Which zone of the table prices this run.
 *
 * The answer is now arithmetic rather than string matching: a run of 34 km is
 * in the 1–40 band, and a run of 205 km is in 201–240. That is the whole rule,
 * and it is the rule the printed table states.
 *
 * ## Why the destination is gone
 *
 * A booking's destination used to choose the zone, matched as a substring
 * against a list of aliases somebody maintained per town. It had to go for two
 * reasons, and only the second is about this workbook.
 *
 * It could not express the table. A1 and A2 are both 1–40 km; E1 and E2 are
 * both 161–200. Nothing a destination string can see distinguishes them,
 * because the distinction is not geographic — so a matcher would always have
 * answered A1 and quietly under-quoted every A2 run by ₱247.
 *
 * And it was guessing. "Davao" reaching the Davao card and "Bajada, Davao
 * City" reaching it too is the case that works; "Sta. Cruz" reaching the wrong
 * one of two provinces that both have a Sta. Cruz is the case that produces a
 * wrong invoice nobody notices. A band cannot be wrong in that way — it is
 * derived from a distance the trip already carries.
 *
 * ## Two zones over one band, and who breaks the tie
 *
 * `covering()` returns every zone whose band holds the distance, which for a
 * subsidy table is normally two rows. The desk picks, and the pick travels on
 * the trip as `pricing_zone_id`.
 *
 * Absent a pick, `default()` takes the first in card order — A1 over A2, E1
 * over E2, because that is the order the table prints them and the lower of
 * the two figures. Quoting the cheaper of two applicable bands is the error
 * the office catches: it is a figure somebody argues up. The reverse is a
 * customer overcharged by a system that guessed, which is the error nobody
 * reports.
 */
class ZoneResolver
{
    public function __construct(private readonly PricingZoneRepository $zones) {}

    /**
     * The zone that prices a run: the one the desk chose, or the band's
     * default.
     *
     * A chosen zone is honoured only while its band still covers the run. The
     * pick exists to settle A1 against A2 over the same kilometres; it is not
     * a way to price a 700 km run off a 1–40 km line. And `trips.pricing_zone_id`
     * is also where the trace is written, so a trip booked with no distance
     * carries A1 — honouring that after a dispatcher pins the route at 200 km
     * would quote a 200 km haul at the 1–40 km rate for ever. A run that has
     * moved out of its band is re-banded from the distance, and a desk that
     * meant A2 and then corrected the distance out of A2's range picks again.
     */
    public function resolve(?string $zoneId, int $km): ?PricingZone
    {
        $chosen = $this->byId($zoneId);

        return $chosen !== null && $chosen->covers($km) ? $chosen : $this->default($km);
    }

    /** A zone by id, if it is one this company can still quote from. */
    public function byId(?string $zoneId): ?PricingZone
    {
        if ($zoneId === null || $zoneId === '') {
            return null;
        }

        return $this->zones->query()->active()->whereKey($zoneId)->first();
    }

    /**
     * The zone a run falls in when nobody has said otherwise.
     *
     * First in card order, which is the table's own order.
     */
    public function default(int $km): ?PricingZone
    {
        return $this->covering($km)->first();
    }

    /**
     * Every zone whose band covers this distance, in card order.
     *
     * The list the desk chooses from. Two entries is the normal case at the
     * top and middle of a subsidy table, and a client that offers only one
     * would hide half the card.
     *
     * @return Collection<int, PricingZone>
     */
    public function covering(int $km): Collection
    {
        return $this->zones->covering($km);
    }
}
