<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\Domain\Pricing\Models\PricingZone;
use Database\Seeders\SubsidyRateCardSeeder;

/**
 * Lay the subsidy card down first, if this company has no zones.
 *
 * Pricing is zone-only: a trip no zone line covers is saved unpriced, and an
 * unpriced run cannot be delivered or billed. A demo seeder that quotes its
 * trips — and can be run on its own, as `cargo:demo-payroll` runs the payroll
 * one — would otherwise produce a board of "needs a zone" runs on a company
 * with no card. `DemoSeeder` seeds the card before anything else, so under it
 * this finds zones and does nothing.
 */
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
