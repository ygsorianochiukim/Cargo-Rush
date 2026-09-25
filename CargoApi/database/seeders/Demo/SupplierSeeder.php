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

/**
 * Who the fleet buys from, what it spent, and what the diesel cost.
 *
 *     php artisan db:seed --class="Database\Seeders\Demo\SupplierSeeder"
 *
 * Three screens that are empty on a fresh install and have nothing to
 * demonstrate until somebody has bought something: Suppliers, Other Expenses
 * and a unit's service history.
 *
 * ## The servicing is the half worth watching
 *
 * A maintenance job carries its own cost, and the cost lands in the Maintenance
 * column of that truck's daily sheet — not in Other Expenses, which is a list
 * of meals and tarpaulins and was never the place to look up what a gearbox
 * came to. So the jobs below are written through `MaintenanceService`, which is
 * what posts them, and the seeded fleet then reads the same on the sheet, on
 * Profitability and in the Quarterly Summary.
 *
 * Booked-but-not-done jobs are seeded beside completed ones deliberately: a job
 * on the calendar has cost nothing yet, and a null cost is "nobody has told us"
 * rather than a free repair.
 *
 * ## Idempotent
 *
 * Suppliers match on name, jobs on the unit and the kind, expenses and bills on
 * their reference, diesel on the day it took effect. A second run corrects
 * rather than duplicates — and for the servicing that means the sheet is left
 * holding each figure exactly once, which is what `MaintenanceService` is for.
 */
class SupplierSeeder extends Seeder
{
    use AdoptsTrashedRows, SeedsIntoACompany, UpsertsByDay;

    /** name, contact, address, what they supply */
    private const SUPPLIERS = [
        ['Petron Bulk Fuels — Iponan', '0917 555 0501', 'Iponan, Cagayan de Oro', 'Diesel in bulk'],
        ['Cebu Tyre Supply', '0917 555 0502', 'Mandaue City, Cebu', 'Tyres, retreads, alignment'],
        ['Iponan Machine Shop', '0917 555 0503', 'Iponan, Cagayan de Oro', 'Engine and gearbox work'],
        ['Ace Auto Parts', '0917 555 0504', 'Cogon, Cagayan de Oro', 'Filters, belts, spares'],
        ['Northern Batteries', '0917 555 0505', 'Carmen, Cagayan de Oro', 'Batteries and electricals'],
        ['Delfin Hauling', '0917 555 0421', 'Puerto, Cagayan de Oro', 'Trucks hired at a monthly fee'],
    ];

    /**
     * plate, kind, supplier, cost in pesos or null, days from today when it was
     * done (null = still only booked), status.
     */
    private const SERVICING = [
        ['MAR1390', 'Gearbox overhaul', 'Iponan Machine Shop', 48500, -22, 'delivered'],
        ['CBS8862', 'Two front tyres', 'Cebu Tyre Supply', 17600, -15, 'delivered'],
        ['NCR 4412', 'Oil and filter change', 'Ace Auto Parts', 3200, -9, 'delivered'],
        ['NCR 9032', 'Battery replacement', 'Northern Batteries', 6400, -4, 'delivered'],
        // Booked, not done, and therefore not costed. The state most jobs spend
        // most of their life in.
        ['ILO 2204', 'Brake pad replacement', 'Cebu Tyre Supply', null, null, 'scheduled'],
        ['SHR 2001', 'Annual inspection prep', 'Iponan Machine Shop', null, null, 'scheduled'],
    ];

    /**
     * Everything that is spend but not diesel, crew or servicing.
     *
     * category key, payee, supplier or null, pesos, days from today, plate or
     * null, note, status.
     */
    private const EXPENSES = [
        ['food', 'Kuya Jun Carinderia', null, 1850, -2, 'MAR1390', 'Crew meals, Pagadian run', 'active'],
        ['toll-parking', 'Laguindingan Terminal', null, 640, -3, 'CBS8862', 'Terminal and parking fees', 'active'],
        ['lodging', 'Pension Cagayan', null, 2400, -6, 'NCR 4412', 'Overnight, delayed unloading', 'active'],
        ['supplies', 'Ace Auto Parts', 'Ace Auto Parts', 5300, -8, null, 'Straps, tarpaulin and rope', 'active'],
        ['permits', 'LTO Region X', null, 9800, -12, 'DVO 7731', 'Registration renewal', 'active'],
        ['office', 'Vista Realty', null, 35000, -14, null, 'Office rent, this month', 'active'],
        // Filed and not yet approved, so the list has both states on it.
        ['other', 'Cagayan Print Shop', null, 1200, -1, null, 'Waybill booklets', 'pending'],
    ];

    /**
     * Bills from suppliers that the office has not settled yet.
     *
     * Payables with a supplier behind them rather than a typed payee, which is
     * what the Payables screen groups by and what a statement of account is
     * reconciled against.
     */
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

            // Handed to the service as the job to correct rather than matched
            // inside it, because that is how the sheet stays right: it takes
            // the old figure off the day it was on before putting the new one
            // on the day it belongs to.
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

    /**
     * Six weeks of pump prices.
     *
     * The rate card prices a run partly off what diesel is doing — a bracket
     * carries a baseline and a step — so a single price is a fuel adjustment
     * that can never have moved. A short history is what makes the surcharge on
     * a quote a number that came from somewhere.
     */
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
