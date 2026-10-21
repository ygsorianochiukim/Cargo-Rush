<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Pricing\Models\TruckSize;
use Illuminate\Database\Seeder;

class TruckSizeSeeder extends Seeder
{
    /** @var array<int, array{0: string, 1: string, 2: int}> */
    private const SIZES = [
        ['small-truck', 'Small Truck', 17],
        ['standard-truck', 'Standard Truck', 28],
        ['10-wheeler', '10 Wheeler', 54],
    ];

    public function run(): void
    {
        foreach (self::SIZES as [$key, $name, $cbm]) {
            TruckSize::updateOrCreate(['key' => $key], ['name' => $name, 'cbm' => $cbm]);
        }
    }
}
