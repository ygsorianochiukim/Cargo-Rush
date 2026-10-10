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

    public function query(): Builder
    {
        return PricingZone::query()
            ->with(['brackets.truckCategory'])
            ->inBandOrder();
    }

    protected function searchable(): array
    {
        return ['name', 'code', 'notes'];
    }

    /** @return Collection<int, PricingZone> */
    public function active(): Collection
    {
        return $this->query()->where('status', StatusValue::Active->value)->get();
    }

    /** @return Collection<int, PricingZone> */
    public function covering(int $km): Collection
    {
        return $this->query()->active()->covering($km)->get();
    }

    public function findByCode(string $code): ?PricingZone
    {
        return $this->query()->where('code', $code)->first();
    }
}
