<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Fuel\Models\FuelBudget;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/** Fuel and billing. Every figure is integer centavos. */
class MoneySeeder extends Seeder
{
    public function run(): void
    {
        $this->fuel();
        $this->invoices();
    }

    private function fuel(): void
    {
        FuelBudget::updateOrCreate(['date' => Carbon::today()], [
            'daily_budget_cents' => 4_500_000,
            'currency' => 'PHP',
            'open_requests' => 3,
        ]);

        $vehicles = Vehicle::query()->pluck('id', 'plate');
        $drivers = Driver::query()->pluck('id', 'name');

        $rows = [
            ['NCR 4412', 'Marco Reyes', 120.0, 780_000, 184320, 'RC-99120', -6, 'active'],
            ['CEB 1180', 'Liza Tan', 85.0, 552_500, 96110, 'RC-99118', -10, 'active'],
            ['NCR 9032', 'Ana Villar', 140.0, 910_000, 133540, 'RC-99115', -18, 'pending'],
            ['ILO 2204', 'Rico Santos', 70.0, 455_000, 58230, 'RC-99112', -27, 'active'],
            ['DVO 7731', 'Paolo Uy', 95.0, 617_500, 241870, 'RC-99109', -50, 'cancelled'],
            ['MAR1390', 'Marco Reyes', 160.0, 1_040_000, 184320, 'RC-99105', -2, 'active'],
        ];

        foreach ($rows as [$plate, $driver, $litres, $cents, $odo, $receipt, $hours, $status]) {
            FuelRecord::updateOrCreate(['receipt_no' => $receipt], [
                'vehicle_id' => $vehicles[$plate] ?? null,
                'driver_id' => $drivers[$driver] ?? null,
                'litres' => $litres,
                'amount_cents' => $cents,
                'currency' => 'PHP',
                'odometer_km' => $odo,
                'logged_at' => Carbon::now()->addHours($hours),
                'status' => $status,
            ]);
        }
    }

    private function invoices(): void
    {
        $customers = Customer::query()->pluck('id', 'name');

        // number, customer or null, payee or null, issued, due, cents, direction, status.
        // `paid` is a settled document — it used to be written as `delivered`,
        // which is the word for a closed-out haul and made collected money
        // impossible to count separately from delivered trips.
        $rows = [
            ['INV-2026-0441', 'Batangas Hardware Co.', null, -13, 17, 12_500_000, 'receivable', 'pending'],
            ['INV-2026-0440', 'Highland Retail', null, -36, -6, 28_900_000, 'receivable', 'overdue'],
            ['INV-2026-0439', 'Metro Grocers', null, -22, 8, 18_400_000, 'receivable', 'paid'],
            ['INV-2026-0438', 'Southline Trading', null, -18, 12, 4_300_000, 'receivable', 'pending'],
            ['BILL-2026-0113', null, 'Petron Fleet Card', -8, 7, 96_200_000, 'payable', 'pending'],
            ['BILL-2026-0112', null, 'Cebu Tyre Supply', -26, -11, 15_750_000, 'payable', 'paid'],
        ];

        foreach ($rows as [$number, $customer, $payee, $issued, $due, $cents, $direction, $status]) {
            Invoice::updateOrCreate(['number' => $number], [
                'customer_id' => $customer === null ? null : ($customers[$customer] ?? null),
                'payee' => $payee,
                'issued_at' => Carbon::today()->addDays($issued),
                'due_at' => Carbon::today()->addDays($due),
                'amount_cents' => $cents,
                'currency' => 'PHP',
                'direction' => $direction,
                'status' => $status,
                // A settled document records when the money arrived; deriving
                // it from `updated_at` would move on any later correction.
                'paid_at' => $status === 'paid' ? Carbon::today()->addDays($due) : null,
            ]);
        }
    }
}
