<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Notification\Models\NotificationItem;
use Database\Seeders\Concerns\SeedsIntoACompany;
use Database\Seeders\SubsidyRateCardSeeder;
use Illuminate\Database\Seeder;

/**
 * A worked example of the whole system, for a walkthrough or a fresh test run.
 *
 *     php artisan db:seed --class="Database\Seeders\Demo\DemoSeeder"
 *
 * Deliberately **not** part of `DatabaseSeeder`. A live install starts empty
 * and gets real records; nobody wants to explain to a client why their fleet
 * contains a truck called MAR1390 that they have never owned.
 *
 * The ledger rows are the one part worth keeping regardless: they are a
 * transcription of the source workbook, and `FinanceRollupTest` asserts the
 * roll-up against the figures that workbook prints.
 */
class DemoSeeder extends Seeder
{
    use SeedsIntoACompany;

    public function run(): void
    {
        // Enters the company once for the whole walkthrough. Each seeder below
        // enters it again on its own account — nesting costs nothing and is
        // what lets any of them be run alone.
        $this->intoCompany(function (): void {
            $this->call([
                /**
                 * The rate card first, because everything below is priced off
                 * it.
                 *
                 * Deliberately not part of provisioning — it is one haulier's
                 * negotiated subsidy table and laying it on every company that
                 * registers would hand them somebody else's prices. A
                 * walkthrough is exactly the case it is right for: quotes come
                 * off a published card rather than off a flat tariff, which is
                 * what the Rate Card screen is there to show.
                 */
                SubsidyRateCardSeeder::class,
                // The company's own side: the accounts, the crew, the units and
                // the customers everything below hangs off.
                FleetSeeder::class,
                // The roster and a fortnight of the truck sheet, so there is a
                // payroll to run. Before `PeopleSeeder`, which files leave,
                // store credit and allowances against these people.
                PayrollSeeder::class,
                PeopleSeeder::class,
                // The outside trucks, in the order the money depends on: the
                // partners first, because two of the hired units are owed to
                // one of them.
                PartnerSeeder::class,
                HiredFleetSeeder::class,
                OperationsSeeder::class,
                MoneySeeder::class,
                // After the fleet, because the servicing it costs is charged
                // against a unit's own daily sheet.
                SupplierSeeder::class,
                LedgerSeeder::class,
                // Last, and the only one that writes outside the demo company:
                // it pins this company's yard and puts two neighbouring
                // hauliers on the platform, so the carrier list a customer
                // picks from is a choice rather than a single card.
                CarrierSeeder::class,
            ]);

            $this->notifications();
        });
    }

    /**
     * A starting feed.
     *
     * Written directly rather than through NotificationService, because these
     * are back-dated examples of things that already happened — pushing them
     * through the service would stamp them all with now().
     */
    private function notifications(): void
    {
        $rows = [
            ['incident', 'Incident INC-0231 raised', 'Ana Villar reported a traffic hold on Kennon Road', 'danger', false, 48],
            ['fleet', 'DVO 7731 service due', 'Only 130 km left before the scheduled interval', 'warning', false, 120],
            ['billing', 'INV-2026-0440 is overdue', 'Highland Retail has 289,000 outstanding', 'danger', false, 300],
            ['clipboard', 'Proof of delivery uploaded', 'POD-8841 for CR-24818', 'success', true, 360],
            ['profile', 'Licence expiring soon', 'Grace Lim licence expires 29 Aug 2026', 'warning', true, 1440],
        ];

        foreach ($rows as [$icon, $title, $detail, $tone, $read, $minutesAgo]) {
            $item = NotificationItem::updateOrCreate(['title' => $title], [
                'icon' => $icon,
                'detail' => $detail,
                'tone' => $tone,
                'read' => $read,
            ]);

            $item->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
        }
    }
}
