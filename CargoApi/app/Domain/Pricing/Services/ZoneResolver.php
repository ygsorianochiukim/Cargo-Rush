<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Repositories\PricingZoneRepository;
use Illuminate\Support\Collection;

class ZoneResolver
{
    public function __construct(private readonly PricingZoneRepository $zones) {}

    public function resolve(?string $zoneId, int $km, bool $pinned = false): ?PricingZone
    {
        $chosen = $this->byId($zoneId);

        if ($chosen !== null && $pinned) {
            return $chosen;
        }

        return $chosen !== null && $chosen->covers($km) ? $chosen : $this->default($km);
    }

    public function byId(?string $zoneId): ?PricingZone
    {
        if ($zoneId === null || $zoneId === '') {
            return null;
        }

        return $this->zones->query()->active()->whereKey($zoneId)->first();
    }

    public function default(int $km): ?PricingZone
    {
        return $this->covering($km)->first();
    }

    /** @return Collection<int, PricingZone> */
    public function covering(int $km): Collection
    {
        return $this->zones->covering($km);
    }
}
