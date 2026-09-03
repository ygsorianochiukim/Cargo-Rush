<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Finance\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * The starting set of expense categories.
 *
 * Configuration, not data — which is why this runs on a fresh install beside
 * the navigation. An expense cannot be filed without a category, so an empty
 * table is a module nobody can use rather than one waiting for its first row.
 *
 * The office is expected to edit this list: rename what it calls things, add
 * what this misses, switch off what it does not spend on. `key` is what makes
 * that safe — rows point at it, so a category renamed in the UI keeps every
 * peso already filed against it.
 *
 * `Fuel` is here despite `ledger_entries.fuel_cents` existing, because a fill
 * bought out of pocket on the road is a receipt somebody hands in, not a
 * figure the office keys into a daily sheet. Note that the two are added, not
 * reconciled: entering the same fill in both places counts it twice.
 */
class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['food', 'Food', 'Meals and provisions for the crew on the road.', 'shipments', 10],
            ['fuel', 'Fuel', 'Diesel bought out of pocket, not keyed into the daily sheet.', 'fuel', 20],
            ['toll-parking', 'Toll & Parking', 'Toll gates, terminal fees and parking.', 'route', 30],
            ['repairs', 'Repairs', 'Roadside repairs and parts outside scheduled maintenance.', 'gauge', 40],
            ['lodging', 'Lodging', 'Overnight accommodation on long hauls.', 'profile', 50],
            ['supplies', 'Supplies', 'Straps, tarpaulins, ropes and consumables.', 'clipboard', 60],
            ['permits', 'Permits & Fees', 'Registration, clearances and government fees.', 'billing', 70],
            ['office', 'Office & Admin', 'Rent, utilities and back-office costs. Usually fleet overhead.', 'dashboard', 80],
            ['other', 'Other', 'Anything the list above has no place for yet.', 'wallet', 90],
        ];

        foreach ($categories as [$key, $name, $description, $icon, $position]) {
            // `firstOrCreate`, not `updateOrCreate`, and the difference matters
            // on the second run. These rows belong to the office once they
            // exist — renamed, reordered, switched off — and re-seeding must
            // not undo that. Matched on `key` so a renamed category is
            // recognised rather than duplicated.
            ExpenseCategory::firstOrCreate(['key' => $key], [
                'name' => $name,
                'description' => $description,
                'icon' => $icon,
                'position' => $position,
            ]);
        }
    }
}
