<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Notification\Models\NotificationItem;
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
    public function run(): void
    {
        $this->call([
            FleetSeeder::class,
            OperationsSeeder::class,
            MoneySeeder::class,
            LedgerSeeder::class,
        ]);

        $this->notifications();
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
