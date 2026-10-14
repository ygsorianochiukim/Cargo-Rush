<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Pricing\Models\TruckCategory;
use Illuminate\Database\Seeder;

class TruckCategorySeeder extends Seeder
{
    /** @var array<int, array{0: string, 1: string, 2: string, 3: int|null}> */
    private const CATEGORIES = [
        ['dry-goods', 'Dry Goods', 'General cargo — the default for most runs.', null],
        ['freezer', 'Freezer / Reefer', 'Temperature-controlled. Usually carries a premium.', null],
        ['flatbed', 'Flatbed', 'Oversized or awkward loads that will not go in a box.', null],
        ['tanker', 'Tanker', 'Liquids in bulk.', null],
        ['10-wheeler', '10 Wheeler', '54 cbm. Priced off the 28 cbm fleet line, divided by 28 and times 54.', 54],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $position => [$key, $name, $description, $cbm]) {
            TruckCategory::updateOrCreate(
                ['key' => $key],
                ['name' => $name, 'description' => $description, 'cbm' => $cbm, 'position' => $position],
            );
        }
    }
}
