<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Shared\Enums\StatusValue;
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
            ['toll-parking', 'Toll & Parking', 'Toll gates, terminal fees and parking.', 'route', 30],
            ['lodging', 'Lodging', 'Overnight accommodation on long hauls.', 'profile', 50],
            ['supplies', 'Supplies', 'Straps, tarpaulins, ropes and consumables.', 'clipboard', 60],
            ['permits', 'Permits & Fees', 'Registration, clearances and government fees.', 'billing', 70],
            ['office', 'Office & Admin', 'Rent, utilities and back-office costs. Usually fleet overhead.', 'dashboard', 80],
            ['other', 'Other', 'Anything the list above has no place for yet.', 'wallet', 90],
        ];

        /**
         * What a unit costs to run is not filed here any more.
         *
         * `repairs` and `fuel` were on this list, which made the Other Expenses
         * screen the place an oil change and a roadside repair were recorded —
         * a list otherwise full of meals and tarpaulins doubling as the fleet's
         * service history, with neither findable.
         *
         * Both now have a home of their own. A service, a repair or an oil
         * change is a **maintenance job** on the unit, and what it cost lands
         * in that truck's Maintenance column on the daily sheet. Diesel is the
         * Fuel module and the sheet's own Fuel column.
         *
         * Switched off rather than deleted, and the difference matters: every
         * peso already filed against them keeps its category, the office can
         * still read its own history, and a firm that genuinely wants one back
         * can switch it on again. Deleting would have orphaned the rows —
         * `expenses.category_id` is `restrictOnDelete`, so it would simply have
         * failed instead.
         *
         * `whereDoesntHave` is what stops this being destructive twice over:
         * the moment anything is filed against one of them it is left alone for
         * good, so an office that switches Repairs back on and uses it keeps
         * it. One that switches it on and files nothing will find it off again
         * after the next seed — which is the honest trade for a rule stated in
         * a seeder rather than in a settings screen, and the cheaper mistake of
         * the two.
         */
        ExpenseCategory::query()
            ->whereIn('key', ['repairs', 'fuel'])
            ->where('status', StatusValue::Active->value)
            ->whereDoesntHave('expenses')
            ->update(['status' => StatusValue::Inactive->value]);

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
