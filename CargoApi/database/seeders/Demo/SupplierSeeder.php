<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Pricing\Models\DieselPrice;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Vehicle\Models\MaintenanceJob;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Services\MaintenanceService;
use Database\Seeders\Concerns\AdoptsTrashedRows;
use Database\Seeders\Concerns\SeedsIntoACompany;
use Database\Seeders\Concerns\UpsertsByDay;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class SupplierSeeder extends Seeder
{
    use AdoptsTrashedRows, SeedsIntoACompany, UpsertsByDay;

    private const SUPPLIERS = [
        ['Petron Bulk Fuels — Iponan', '0917 555 0501', 'Iponan, Cagayan de Oro', 'Diesel in bulk'],
        ['Cebu Tyre Supply', '0917 555 0502', 'Mandaue City, Cebu', 'Tyres, retreads, alignment'],
        ['Iponan Machine Shop', '0917 555 0503', 'Iponan, Cagayan de Oro', 'Engine and gearbox work'],
        ['Ace Auto Parts', '0917 555 0504', 'Cogon, Cagayan de Oro', 'Filters, belts, spares'],
        ['Northern Batteries', '0917 555 0505', 'Carmen, Cagayan de Oro', 'Batteries and electricals'],
        ['Delfin Hauling', '0917 555 0421', 'Puerto, Cagayan de Oro', 'Trucks hired at a monthly fee'],
    ];

    private const SERVICING = [
        ['MAR1390', 'Gearbox overhaul', 'Iponan Machine Shop', 48500, -22, 'delivered'],
        ['CBS8862', 'Two front tyres', 'Cebu Tyre Supply', 17600, -15, 'delivered'],
        ['NCR 4412', 'Oil and filter change', 'Ace Auto Parts', 3200, -9, 'delivered'],
        ['NCR 9032', 'Battery replacement', 'Northern Batteries', 6400, -4, 'delivered'],
        ['ILO 2204', 'Brake pad replacement', 'Cebu Tyre Supply', null, null, 'scheduled'],
        ['SHR 2001', 'Annual inspection prep', 'Iponan Machine Shop', null, null, 'scheduled'],
    ];

    private const EXPENSES = [
        ['food', 'Kuya Jun Carinderia', null, 1850, -2, 'MAR1390', 'Crew meals, Pagadian run', 'active'],
        ['toll-parking', 'Laguindingan Terminal', null, 640, -3, 'CBS8862', 'Terminal and parking fees', 'active'],
        ['lodging', 'Pension Cagayan', null, 2400, -6, 'NCR 4412', 'Overnight, delayed unloading', 'active'],
        ['supplies', 'Ace Auto Parts', 'Ace Auto Parts', 5300, -8, null, 'Straps, tarpaulin and rope', 'active'],
        ['permits', 'LTO Region X', null, 9800, -12, 'DVO 7731', 'Registration renewal', 'active'],
        ['office', 'Vista Realty', null, 35000, -14, null, 'Office rent, this month', 'active'],
        ['other', 'Cagayan Print Shop', null, 1200, -1, null, 'Waybill booklets', 'pending'],
    ];

    private const BILLS = [
        ['BILL-2026-0201', 'Petron Bulk Fuels — Iponan', 186000, -11, 9, 'pending'],
        ['BILL-2026-0202', 'Cebu Tyre Supply', 43800, -19, -4, 'overdue'],
        ['BILL-2026-0203', 'Iponan Machine Shop', 48500, -22, 8, 'paid'],
    ];

    public function __construct(private readonly MaintenanceService $maintenance) {}

    public function run(): void
    {
        $this->intoCompany(function (): void {
            $this->suppliers();
            $this->servicing();
            $this->expenses();
            $this->bills();
            $this->diesel();

            $this->report();
        });
    }

    private function suppliers(): void
    {
        foreach (self::SUPPLIERS as [$name, $contact, $address, $supplies]) {
            $this->restoreOrCreate(Supplier::class, ['name' => $name], [
                'contact' => $contact,
                'address' => $address,
                'supplies' => $supplies,
                'status' => StatusValue::Active->value,
            ]);
        }
    }

    private function servicing(): void
    {
        $vehicles = Vehicle::query()->get()->keyBy('plate');
        $suppliers = Supplier::query()->pluck('id', 'name');

        foreach (self::SERVICING as [$plate, $kind, $supplier, $peso, $days, $status]) {
            $vehicle = $vehicles[$plate] ?? null;

            if ($vehicle === null) {
                continue;
            }

            $done = $days === null ? null : Carbon::today()->addDays($days);

            $existing = MaintenanceJob::query()
                ->where('vehicle_id', $vehicle->getKey())
                ->where('kind', $kind)
                ->first();

            $this->maintenance->save($vehicle, [
                'kind' => $kind,
                'due_at' => ($done ?? Carbon::today()->addDays(9))->toDateString(),
                'next_service_km' => (int) $vehicle->next_service_km,
                'cost_cents' => $peso === null ? null : $peso * 100,
                'completed_on' => $done?->toDateString(),
                'supplier_id' => $suppliers[$supplier] ?? null,
                'reference' => $done === null ? null : 'SO-'.$done->format('ymd').'-'.substr((string) preg_replace('/\D/', '', $plate), 0, 4),
                'status' => $status,
            ], $existing);
        }
    }

    private function expenses(): void
    {
        $categories = ExpenseCategory::query()->pluck('id', 'key');
        $suppliers = Supplier::query()->pluck('id', 'name');
        $vehicles = Vehicle::query()->pluck('id', 'plate');

        foreach (self::EXPENSES as $i => $row) {
            [$key, $payee, $supplier, $peso, $days, $plate, $note, $status] = $row;

            $category = $categories[$key] ?? null;

            if ($category === null) {
                continue;
            }

            $this->restoreOrCreate(Expense::class, ['reference' => sprintf('EXP-DEMO-%03d', $i + 1)], [
                'category_id' => $category,
                'supplier_id' => $suppliers[$supplier] ?? null,
                'vehicle_id' => $plate === null ? null : ($vehicles[$plate] ?? null),
                'date' => Carbon::today()->addDays($days)->toDateString(),
                'amount_cents' => $peso * 100,
                'currency' => 'PHP',
                'payee' => $payee,
                'note' => $note,
                'status' => $status,
            ]);
        }
    }

    private function bills(): void
    {
        $suppliers = Supplier::query()->pluck('id', 'name');

        foreach (self::BILLS as [$number, $supplier, $peso, $issued, $due, $status]) {
            $this->restoreOrCreate(Invoice::class, ['number' => $number], [
                'supplier_id' => $suppliers[$supplier] ?? null,
                'payee' => $supplier,
                'issued_at' => Carbon::today()->addDays($issued),
                'due_at' => Carbon::today()->addDays($due),
                'amount_cents' => $peso * 100,
                'currency' => 'PHP',
                'direction' => InvoiceDirection::Payable->value,
                'status' => $status,
                'paid_at' => $status === 'paid' ? Carbon::today()->addDays($due) : null,
            ]);
        }
    }

    private function diesel(): void
    {
        $prices = [6420, 6535, 6610, 6480, 6390, 6455];

        foreach ($prices as $weeksAgo => $cents) {
            $on = Carbon::today()->subWeeks(count($prices) - 1 - $weeksAgo)->startOfWeek();

            $this->upsertOn(DieselPrice::class, [], 'effective_on', $on, [
                'price_per_litre_cents' => $cents,
                'currency' => 'PHP',
                'source' => 'Petron Iponan pump board',
            ]);
        }
    }

    private function report(): void
    {
        $this->command?->info(sprintf(
            '  %d suppliers, %d costed services, %d expenses and %d weeks of diesel prices.',
            Supplier::query()->count(),
            MaintenanceJob::query()->whereNotNull('cost_cents')->count(),
            Expense::query()->count(),
            DieselPrice::query()->count(),
        ));
    }
}
