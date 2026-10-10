<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\Domain\Pricing\Models\PricingZone;
use Database\Seeders\SubsidyRateCardSeeder;

trait NeedsAZoneCard
{
    protected function ensureZoneCard(): void
    {
        if (PricingZone::query()->exists()) {
            return;
        }

        (new SubsidyRateCardSeeder)->run();
    }
}
