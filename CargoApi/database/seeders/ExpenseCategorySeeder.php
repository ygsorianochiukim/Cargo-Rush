<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['food', 'Food', 'Meals and provisions for the crew on the road.', 'shipments', 10],
            ['toll-parking', 'Toll & Parking', 'Toll gates, terminal fees and parking.', 'route', 30],
            ['lodging', 'Lodging', 'Overnight accommodation on long hauls.', 'profile', 50],
            ['supplies', 'Supplies', 'Straps, tarpaulins, ropes and consumables.', 'clipboard', 60],
            ['permits', 'Permits & Fees', 'Registration, clearances and government fees.', 'billing', 70],
            ['office', 'Office & Admin', 'Rent, utilities and back-office costs. Usually fleet overhead.', 'dashboard', 80],
            ['other', 'Other', 'Anything the list above has no place for yet.', 'wallet', 90],
        ];

        ExpenseCategory::query()
            ->whereIn('key', ['repairs', 'fuel'])
            ->where('status', StatusValue::Active->value)
            ->whereDoesntHave('expenses')
            ->update(['status' => StatusValue::Inactive->value]);

        foreach ($categories as [$key, $name, $description, $icon, $position]) {
            ExpenseCategory::firstOrCreate(['key' => $key], [
                'name' => $name,
                'description' => $description,
                'icon' => $icon,
                'position' => $position,
            ]);
        }
    }
}
