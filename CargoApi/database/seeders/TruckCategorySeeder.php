<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Pricing\Models\TruckCategory;
use Illuminate\Database\Seeder;

class TruckCategorySeeder extends Seeder
{
    /** @var array<int, array{0: string, 1: string, 2: string}> */
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
