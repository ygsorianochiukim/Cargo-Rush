<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Pricing\Models\TruckCategory;
use Illuminate\Database\Seeder;

/**
 * The kinds of unit a haulier starts with.
 *
 * Per company, and seeded at provisioning like the roles and the positions are
 * — a firm should be able to price a freezer run on the day it signs up rather
 * than having to invent the vocabulary first.
 *
 * Deliberately short. These four cover what a Philippine fleet actually runs,
 * and a list that tried to be exhaustive would be a list nobody reads to the
 * bottom of. Anything else the office adds itself, which is the point of the
 * table being editable.
 *
 * `updateOrCreate` on the key, so a wording fix in a later release reaches
 * installs that already exist — and so re-provisioning a company tops it up
 * rather than duplicating it. What is *not* touched is the rates: those live on
 * the rate card and are the firm's.
 */
class TruckCategorySeeder extends Seeder
{
    /**
     * key, name, description
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const CATEGORIES = [
        ['dry-goods', 'Dry Goods', 'General cargo — the default for most runs.'],
        ['freezer', 'Freezer / Reefer', 'Temperature-controlled. Usually carries a premium.'],
        ['flatbed', 'Flatbed', 'Oversized or awkward loads that will not go in a box.'],
        ['tanker', 'Tanker', 'Liquids in bulk.'],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $position => [$key, $name, $description]) {
            TruckCategory::updateOrCreate(
                ['key' => $key],
                ['name' => $name, 'description' => $description, 'position' => $position],
            );
        }
    }
}
