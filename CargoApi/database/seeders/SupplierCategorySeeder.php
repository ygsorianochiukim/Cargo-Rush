<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Supplier\Models\SupplierCategory;
use Illuminate\Database\Seeder;

class SupplierCategorySeeder extends Seeder
{
    public const UTILITIES = 'UTILITIES';

    public function run(): void
    {
        SupplierCategory::withTrashed()->firstOrCreate(['name' => self::UTILITIES]);
    }
}
