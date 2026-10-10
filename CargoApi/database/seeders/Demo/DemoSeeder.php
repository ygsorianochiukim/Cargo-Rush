<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Notification\Models\NotificationItem;
use Database\Seeders\Concerns\SeedsIntoACompany;
use Database\Seeders\SubsidyRateCardSeeder;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    use SeedsIntoACompany;

    public function run(): void
    {
        $this->intoCompany(function (): void {
            $this->call([
                SubsidyRateCardSeeder::class,
                FleetSeeder::class,
                PayrollSeeder::class,
                PeopleSeeder::class,
                PartnerSeeder::class,
                HiredFleetSeeder::class,
                OperationsSeeder::class,
                MoneySeeder::class,
                SupplierSeeder::class,
                LedgerSeeder::class,
                CarrierSeeder::class,
            ]);

            $this->notifications();
        });
    }

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
