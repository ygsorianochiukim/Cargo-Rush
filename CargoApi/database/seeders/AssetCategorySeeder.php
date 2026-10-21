<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Asset\Models\AssetCategory;
use Illuminate\Database\Seeder;

class AssetCategorySeeder extends Seeder
{
    public const NAMES = [
        'IT EQUIPMENT',
        'COMMUNICATION',
        'OFFICE EQUIPMENT',
        'FURNITURE',
        'TOOLS',
        'SAFETY GEAR',
        'TRUCK ACCESSORIES',
    ];

    public function run(): void
    {
        foreach (self::NAMES as $name) {
            AssetCategory::withTrashed()->firstOrCreate(['name' => $name]);
        }
    }
}
