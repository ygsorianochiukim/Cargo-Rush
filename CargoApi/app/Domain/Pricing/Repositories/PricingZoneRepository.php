<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Repositories;

use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class PricingZoneRepository extends Repository
{
    protected function model(): string
    {
        return PricingZone::class;
    }

    /**
     * Zones always travel with their brackets, in the table's own order.
     *
     * There is no view of a zone that does not show its money, and no quote
     * that can be worked out without it, so loading them separately would be
     * an N+1 on every single path this module has.
     *
     * Ordered by band rather than by name, because the card is read down: A1,
     * A2, B, C … O. Sorting alphabetically would put E1 between D and F and
     * then bury A2 next to it, which is a list nobody can check against the
     * sheet it was typed from.
     */
    public function query(): Builder
    {
        return PricingZone::query()
            ->with(['brackets.truckCategory'])
            ->inBandOrder();
    }

    /**
     * `aliases` is gone from here along with the column.
     *
     * A search that still looked for town names would answer with zones that
     * no longer price anything from them.
     */
    protected function searchable(): array
    {
        return ['name', 'code', 'notes'];
    }

    /**
     * The zones a quote is allowed to use.
     *
     * Inactive zones are kept, not deleted, because a band is switched off
     * when the table is redrawn — and the trips already priced from it still
     * have to be able to name where their figure came from.
     *
     * @return Collection<int, PricingZone>
     */
    public function active(): Collection
    {
        return $this->query()->where('status', StatusValue::Active->value)->get();
    }

    /**
     * Every active zone whose band covers this distance, in card order.
     *
     * Normally two rows on a subsidy table — A1 beside A2 — and the caller
     * decides between them. See `ZoneResolver`.
     *
     * @return Collection<int, PricingZone>
     */
    public function covering(int $km): Collection
    {
        return $this->query()->active()->covering($km)->get();
    }

    public function findByCode(string $code): ?PricingZone
    {
        return $this->query()->where('code', $code)->first();
    }
}
