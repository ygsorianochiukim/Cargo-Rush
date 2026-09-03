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
     * Zones always travel with their brackets.
     *
     * There is no view of a zone that does not show its card, and no quote
     * that can be worked out without one, so loading them separately would be
     * an N+1 on every single path this module has.
     */
    public function query(): Builder
    {
        return PricingZone::query()
            ->with('brackets')
            ->orderBy('position')
            ->orderBy('name');
    }

    protected function searchable(): array
    {
        return ['name', 'code', 'notes'];
    }

    /**
     * The cards a quote is allowed to use.
     *
     * Inactive zones are kept, not deleted, because a zone is switched off
     * when the business stops serving a place — and the trips already priced
     * from it still have to be able to name where their figure came from.
     *
     * @return Collection<int, PricingZone>
     */
    public function active(): Collection
    {
        return $this->query()->where('status', StatusValue::Active->value)->get();
    }

    public function findByCode(string $code): ?PricingZone
    {
        return $this->query()->where('code', $code)->first();
    }
}
