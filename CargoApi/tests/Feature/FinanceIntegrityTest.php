<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Finance\Services\FinanceService;
use App\Domain\Finance\Services\PayablesService;
use App\Domain\Fuel\Console\PostFuelFillsCommand;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerVehicle;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Trucker\Services\WalletService;
use App\Domain\Vehicle\Models\MaintenanceJob;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Services\TruckRentService;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The reconciliation fixes — each figure counted once, and every screen that
 * shows it agreeing with the others.
 *
 * Grouped by the bug each one pins. Money is centavos throughout; ₱10,000 is
 * 1_000_000.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
    $this->driver = Driver::query()->firstOrFail();

    $this->vehicle = fn (array $terms = []) => Vehicle::create([
        'plate' => 'FIN-'.fake()->unique()->numberBetween(1000, 9999),
        'model' => 'Isuzu Forward',
        'registration_no' => fake()->unique()->bothify('REG-####'),
        'capacity_kg' => 15_000,
        'status' => StatusValue::Available->value,
        ...$terms,
    ]);

    /** Book it, run it, close it out on a fleet unit. */
    $this->haul = function (Vehicle $vehicle, int $priceCents = 1_000_000): Trip {
        $trip = Trip::create([
            'customer_id' => $this->customer->getKey(),
            'origin' => 'Iponan', 'destination' => 'Bukidnon', 'cargo' => 'Rice',
            'weight_kg' => 10_000, 'status' => StatusValue::Assigned->value,
            'scheduled_at' => now()->addDay(), 'price_cents' => $priceCents, 'currency' => 'PHP',
            'vehicle_id' => $vehicle->getKey(), 'driver_id' => $this->driver->getKey(),
        ]);

        $this->passPreTripCheck($trip->getKey());

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/complete", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();

        return $trip->refresh();
    };

    /** A roll-up over a named window, as Profitability reads it. */
    $this->period = fn (string $from, string $to) => $this->actingAs($this->admin)
        ->getJson("/api/v1/finance/profitability?from={$from}&to={$to}")
        ->assertOk()
        ->json('data');

    $this->lines = fn (string $from, string $to) => $this->actingAs($this->admin)
        ->getJson("/api/v1/finance/expense-lines?from={$from}&to={$to}")
        ->assertOk()
        ->json('data');

    /** A supplier bill, raised and optionally paid on a day. */
    $this->bill = fn (int $cents, string $issued = '2026-07-01') => $this->actingAs($this->admin)
        ->postJson('/api/v1/billing', [
            'payee' => 'Davao Lubes',
            'customer_id' => $this->customer->id,
            'issued_at' => $issued,
            'due_at' => $issued,
            'amount_cents' => $cents,
            'direction' => 'payable',
        ])->assertCreated()->json('data');

    $this->pay = fn (string $invoiceId, int $cents, string $paidOn) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'direction' => 'payable',
            'amount_cents' => $cents,
            'paid_on' => $paidOn,
            'method' => 'bank_transfer',
            'allocations' => [['invoice_id' => $invoiceId, 'amount_cents' => $cents]],
        ])->assertCreated();
});

describe('a revenue-share truck (fix 1)', function (): void {
    it('reads ₱10,000 at a 15% cut as ₱1,500 of actual income', function (): void {
        $owner = Trucker::factory()->approved()->create(['name' => 'Delfin Uy', 'licence_no' => null]);
        $truck = ($this->vehicle)([
            'arrangement' => VehicleArrangement::RentedShare->value,
            'owner_trucker_id' => $owner->getKey(),
            'share_bp' => 1500,
        ]);

        ($this->haul)($truck, 1_000_000);

        $today = now()->toDateString();
        $totals = ($this->period)($today, $today)['totals'];

        // The owner's ₱8,500 is an expense on the sheet and still owed from
        // their wallet — both true, and counted once.
        expect($totals['trip_income_cents'])->toBe(1_000_000)
            ->and($totals['owner_share_cents'])->toBe(850_000)
            ->and($totals['net_income_cents'])->toBe(150_000)
            ->and($totals['payables_cents'])->toBe(850_000)
            ->and($totals['payables_already_costed_cents'])->toBe(850_000)
            ->and($totals['actual_income_cents'])->toBe(150_000);
    });
});

describe('supplier bills owed (fix 2)', function (): void {
    it('leaves a cancelled bill out, and agrees with the Payables page', function (): void {
        $open = ($this->bill)(300_000, now()->subDays(3)->toDateString());
        $cancelled = ($this->bill)(900_000, now()->subDays(3)->toDateString());
        Invoice::query()->whereKey($cancelled['id'])->update(['status' => StatusValue::Cancelled->value]);

        $page = $this->actingAs($this->admin)->getJson('/api/v1/finance/payables')->assertOk()->json('data');
        $bills = collect($page['groups'])->firstWhere('key', 'supplier_bills');

        $owed = app(PayablesService::class)->outstandingAsOf(now());

        expect($bills['total_cents'])->toBe(300_000)
            ->and(collect($bills['lines'])->pluck('id')->all())->toBe([$open['id']])
            ->and($owed)->toBe($page['total_cents']);
    });
});

describe('payroll in Finance (fix 3)', function (): void {
    beforeEach(function (): void {
        $this->vehicleForSheet = ($this->vehicle)();
        $this->sheet = Truck::create([
            'label' => 'Truck 9', 'plate' => $this->vehicleForSheet->plate,
            'vehicle_id' => $this->vehicleForSheet->id, 'position' => 9,
        ]);

        $this->employee = Employee::create([
            'employee_no' => 'EMP-F1', 'first_name' => 'Marco', 'last_name' => 'Reyes',
            'position' => 'Driver', 'contact' => '0917 555 0123', 'hired_on' => '2026-01-05',
            'status' => 'active', 'driver_id' => $this->driver->id,
        ]);

        // What the sheet already recorded paying him in the period: ₱3,000.
        LedgerEntry::create([
            'truck_id' => $this->sheet->id, 'date' => '2026-07-10', 'driver_id' => $this->driver->id,
            'trip_income_cents' => 2_000_000, 'driver_salary_cents' => 300_000,
        ]);

        /** A run for 1–15 July: ₱5,000 gross, ₱500 withheld, ₱4,500 net. */
        $this->run = function (string $status): PayRun {
            $run = PayRun::create([
                'period_start' => '2026-07-01', 'period_end' => '2026-07-15',
                'pay_date' => '2026-07-17', 'status' => PayRun::DRAFT,
            ]);

            PayRunLine::create([
                'pay_run_id' => $run->id, 'employee_id' => $this->employee->id,
                'employee_no' => 'EMP-F1', 'name' => 'Marco Reyes', 'position' => 'Driver',
                'basic_cents' => 500_000,
                'sss_cents' => 20_000, 'philhealth_cents' => 10_000, 'pagibig_cents' => 5_000,
                'withholding_tax_cents' => 15_000,
                'gross_cents' => 500_000, 'deductions_cents' => 50_000, 'net_cents' => 450_000,
            ]);

            $run->forceFill([
                'status' => $status,
                'approved_at' => Carbon::parse('2026-07-16 09:00'),
                'paid_at' => $status === PayRun::PAID ? Carbon::parse('2026-07-17 09:00') : null,
            ])->save();

            return $run;
        };
    });

    it('adds a paid run beyond the crew pay the sheet already holds, once', function (): void {
        ($this->run)(PayRun::PAID);

        $totals = ($this->period)('2026-07-01', '2026-07-31')['totals'];

        // ₱3,000 on the sheet, ₱5,000 on the payslip for the same fortnight:
        // the fortnight cost ₱5,000, not ₱8,000.
        expect($totals['driver_salary_cents'])->toBe(300_000)
            ->and($totals['payroll_cents'])->toBe(200_000)
            ->and($totals['total_expenses_cents'])->toBe(500_000)
            ->and($totals['net_income_cents'])->toBe(1_500_000);
    });

    it('lists the run in the drill-down, which still adds up to the total', function (): void {
        ($this->run)(PayRun::PAID);

        $totals = ($this->period)('2026-07-01', '2026-07-31')['totals'];
        $lines = ($this->lines)('2026-07-01', '2026-07-31');

        expect($lines['total_cents'])->toBe($totals['total_expenses_cents'])
            ->and(collect($lines['lines'])->firstWhere('source', 'payroll')['amount_cents'])->toBe(200_000);
    });

    it('owes the withholdings until the month after the period, already costed', function (): void {
        ($this->run)(PayRun::PAID);

        $july = ($this->period)('2026-07-01', '2026-07-31')['totals'];
        $september = ($this->period)('2026-09-01', '2026-09-30')['totals'];

        expect($july['payables_cents'])->toBe(50_000)
            ->and($july['payables_already_costed_cents'])->toBe(50_000)
            ->and($july['actual_income_cents'])->toBe($july['net_income_cents'])
            // Presumed remitted by the end of August.
            ->and($september['payables_cents'])->toBe(0);
    });

    it('owes an approved run its net pay, less what the sheet already charged', function (): void {
        ($this->run)(PayRun::APPROVED);

        $totals = ($this->period)('2026-07-01', '2026-07-31')['totals'];

        expect($totals['payroll_cents'])->toBe(0)
            ->and($totals['payables_cents'])->toBe(450_000)
            ->and($totals['payables_already_costed_cents'])->toBe(300_000)
            ->and($totals['actual_income_cents'])->toBe(2_000_000 - 300_000 - 150_000);

        $page = $this->actingAs($this->admin)->getJson('/api/v1/finance/payables')->assertOk()->json('data');

        expect(collect($page['groups'])->firstWhere('key', 'payroll')['total_cents'])->toBe(450_000);
    });
});

describe('a partner run priced VAT-inclusive (fix 4)', function (): void {
    it('takes the 12% commission on the net, not on the VAT', function (): void {
        $this->company->update(['prices_include_vat' => true, 'vat_registered' => true]);

        $user = User::factory()->create(['role' => Role::Trucker->value, 'company_id' => $this->company->getKey()]);
        $partner = Trucker::factory()->approved()->create(['user_id' => $user->getKey(), 'name' => 'Boyet']);
        TruckerVehicle::factory()->create(['trucker_id' => $partner->getKey()]);

        // ₱11,200 all-in is ₱10,000 of haul and ₱1,200 of the BIR's VAT.
        $trip = Trip::create([
            'customer_id' => $this->customer->getKey(), 'trucker_id' => $partner->getKey(),
            'origin' => 'Iponan', 'destination' => 'Bukidnon', 'cargo' => 'Rice', 'weight_kg' => 10_000,
            'status' => StatusValue::Pending->value, 'scheduled_at' => now()->addDay(),
            'price_cents' => 1_120_000, 'currency' => 'PHP',
        ]);

        $this->actingAs($user)->postJson("/api/v1/partner/jobs/{$trip->id}/accept")->assertCreated();
        $this->actingAs($user)->postJson("/api/v1/partner/trips/{$trip->id}/start")->assertOk();
        $this->actingAs($user)->postJson("/api/v1/partner/trips/{$trip->id}/deliver", ['receiver_name' => 'Mrs Uy'])->assertOk();

        $entry = WalletEntry::query()->where('trip_id', $trip->id)->firstOrFail();

        expect($trip->refresh()->commission_cents)->toBe(120_000)
            ->and(abs($entry->amount_cents))->toBe(120_000)
            ->and($entry->gross_cents)->toBe(1_000_000);
    });
});

describe('paying a partner out (fix 5)', function (): void {
    beforeEach(function (): void {
        $this->partner = Trucker::factory()->approved()->create(['name' => 'Boyet']);

        $row = fn (WalletEntryKind $kind, int $cents) => WalletEntry::create([
            'trucker_id' => $this->partner->id, 'kind' => $kind->value, 'status' => StatusValue::Paid->value,
            'amount_cents' => $cents, 'occurred_on' => now()->toDateString(),
        ]);

        // ₱8,800 owed on a brokered run, ₱1,200 owed back on a direct one.
        $this->earning = $row(WalletEntryKind::Earning, 880_000);
        $row(WalletEntryKind::Commission, -120_000);
    });

    it('sends the balance, and settles every row that made it', function (): void {
        $payout = app(WalletService::class)->payOut($this->partner, cleared: false);

        expect(abs($payout->amount_cents))->toBe(760_000)
            ->and(WalletEntry::query()->where('trucker_id', $this->partner->id)->whereNull('settled_by')
                ->where('kind', '!=', WalletEntryKind::Payout->value)->count())->toBe(0);

        // In flight: still owed until it lands, and nothing left to send.
        $summary = app(WalletService::class)->summary($this->partner);
        expect($summary['balance_cents'])->toBe(760_000)
            ->and($summary['in_flight_cents'])->toBe(760_000)
            ->and($summary['payable_cents'])->toBe(0);

        app(WalletService::class)->markLanded($payout);

        expect(WalletEntry::balanceFor($this->partner->id))->toBe(0);
    });

    it('refuses to pay named runs worth more than the account stands at', function (): void {
        expect(fn () => app(WalletService::class)->payOut($this->partner, [$this->earning->id]))
            ->toThrow(HttpException::class);
    });
});

describe('a service and the garage bill for it (fix 6)', function (): void {
    it('counts a linked bill once, as the job', function (): void {
        $truck = ($this->vehicle)();
        $bill = ($this->bill)(320_000, '2026-07-05');
        ($this->pay)($bill['id'], 320_000, '2026-07-06');

        $this->actingAs($this->admin)->postJson('/api/v1/maintenance', [
            'vehicle_id' => $truck->id, 'kind' => 'Oil change', 'due_at' => '2026-07-05',
            'cost_cents' => 320_000, 'completed_on' => '2026-07-05', 'invoice_id' => $bill['id'],
        ])->assertCreated();

        $totals = ($this->period)('2026-07-01', '2026-07-31')['totals'];
        $lines = ($this->lines)('2026-07-01', '2026-07-31');

        expect($totals['maintenance_cents'])->toBe(320_000)
            ->and($totals['supplier_bills_cents'])->toBe(0)
            ->and($totals['total_expenses_cents'])->toBe(320_000)
            ->and($lines['total_cents'])->toBe(320_000);
    });

    it('still counts a bill no job names', function (): void {
        $bill = ($this->bill)(320_000, '2026-07-05');
        ($this->pay)($bill['id'], 320_000, '2026-07-06');

        expect(($this->period)('2026-07-01', '2026-07-31')['totals']['supplier_bills_cents'])->toBe(320_000);
    });
});

describe('a sheet row something posted to (fix 7)', function (): void {
    beforeEach(function (): void {
        $truck = ($this->vehicle)();

        $this->actingAs($this->admin)->postJson('/api/v1/maintenance', [
            'vehicle_id' => $truck->id, 'kind' => 'Oil change', 'due_at' => '2026-07-05',
            'cost_cents' => 320_000, 'completed_on' => '2026-07-05',
        ])->assertCreated();

        $this->row = LedgerEntry::query()->firstOrFail();
    });

    it('refuses to overwrite the posted figure, move the day, or delete it', function (): void {
        $this->actingAs($this->admin)->patchJson("/api/v1/ledger/{$this->row->id}", ['maintenance_cents' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('maintenance_cents');

        $this->actingAs($this->admin)->patchJson("/api/v1/ledger/{$this->row->id}", ['date' => '2026-07-09'])
            ->assertStatus(422)->assertJsonValidationErrors('date');

        $this->actingAs($this->admin)->deleteJson("/api/v1/ledger/{$this->row->id}")->assertStatus(422);

        expect($this->row->refresh()->maintenance_cents)->toBe(320_000);
    });

    it('still takes the office’s own columns', function (): void {
        $this->actingAs($this->admin)->patchJson("/api/v1/ledger/{$this->row->id}", [
            'allowance_cents' => 50_000, 'maintenance_cents' => 320_000, 'date' => '2026-07-05',
        ])->assertOk();

        expect($this->row->refresh()->allowance_cents)->toBe(50_000);
    });

    it('refuses to change trip income a delivery posted', function (): void {
        $trip = ($this->haul)(($this->vehicle)());
        $row = LedgerEntry::query()->where('trip_id', $trip->id)->firstOrFail();

        $this->actingAs($this->admin)->patchJson("/api/v1/ledger/{$row->id}", ['trip_income_cents' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('trip_income_cents');
    });
});

describe('rent keyed on the unit, not its plate (fix 8)', function (): void {
    it('charges each unit once a month, and a corrected plate does not charge twice', function (): void {
        $terms = ['arrangement' => VehicleArrangement::Rented->value, 'rent_cents' => 5_000_000, 'owner_name' => 'Delfin'];
        $first = ($this->vehicle)($terms);
        ($this->vehicle)($terms);

        expect(app(TruckRentService::class)->chargeMonth(Carbon::parse('2026-07-01')))->toBe(2);

        $first->update(['plate' => 'NEW-0001']);

        expect(app(TruckRentService::class)->chargeMonth(Carbon::parse('2026-07-01')))->toBe(0)
            ->and(Expense::query()->where('vehicle_id', $first->id)->count())->toBe(1);
    });
});

describe('an invoice raised by hand (fix 9)', function (): void {
    it('counts its net as other income, beside its VAT', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/billing', [
            'customer_id' => $this->customer->id, 'issued_at' => '2026-07-10', 'due_at' => '2026-08-10',
            'amount_cents' => 1_000_000, 'direction' => 'receivable',
        ])->assertCreated();

        $invoice = Invoice::query()->where('direction', 'receivable')->firstOrFail();
        $net = FinanceService::invoiceNetCents($invoice);

        $totals = ($this->period)('2026-07-01', '2026-07-31')['totals'];

        expect($totals['other_income_cents'])->toBe($net)
            ->and($totals['total_income_cents'])->toBe($net)
            ->and($totals['vat_collected_cents'])->toBe((int) $invoice->vat_cents)
            ->and($totals['net_income_cents'])->toBe($net);
    });
});

describe('the ten-day window (fix 10)', function (): void {
    it('is ten days with both ends included', function (): void {
        expect(app(FinanceService::class)->tenDayRange(Carbon::parse('2026-04-05')))
            ->toBe(['from' => '2026-04-05', 'to' => '2026-04-14']);

        $range = $this->actingAs($this->admin)->getJson('/api/v1/finance/profitability')->assertOk()->json('data.range');

        expect(Carbon::parse($range['from'])->diffInDays(Carbon::parse($range['to'])) + 1)->toEqual(10)
            ->and($range['to'])->toBe(now()->toDateString());
    });
});

describe('the Fuel page and the Supplier totals (fix 11)', function (): void {
    it('spends active fills only, and shows pending beside it', function (): void {
        $truck = ($this->vehicle)();
        $fill = fn (string $status, int $cents) => FuelRecord::create([
            'vehicle_id' => $truck->id, 'litres' => 50, 'amount_cents' => $cents, 'odometer_km' => 1000,
            'receipt_no' => 'RC-'.random_int(1000, 9999), 'logged_at' => now(), 'status' => $status,
        ]);
        $fill('active', 300_000);
        $fill('pending', 100_000);

        $this->actingAs($this->admin)->getJson('/api/v1/fuel/budget')->assertOk()
            ->assertJsonPath('data.spent_today_cents', 300_000)
            ->assertJsonPath('data.pending_today_cents', 100_000);
    });

    it('counts servicing done and posted, and bills not cancelled', function (): void {
        $supplier = Supplier::factory()->create(['name' => 'Davao Lubes']);
        $truck = ($this->vehicle)();

        // Booked, quoted, not done: not spend yet.
        MaintenanceJob::create([
            'vehicle_id' => $truck->id, 'kind' => 'Tyres', 'due_at' => '2026-07-20',
            'cost_cents' => 900_000, 'supplier_id' => $supplier->id,
        ]);
        $this->actingAs($this->admin)->postJson('/api/v1/maintenance', [
            'vehicle_id' => $truck->id, 'kind' => 'Oil change', 'due_at' => '2026-07-05',
            'cost_cents' => 320_000, 'completed_on' => '2026-07-05', 'supplier_id' => $supplier->id,
        ])->assertCreated();

        Invoice::create([
            'direction' => 'payable', 'payee' => 'Davao Lubes', 'supplier_id' => $supplier->id,
            'issued_at' => '2026-07-01', 'due_at' => '2026-07-01', 'amount_cents' => 50_000,
            'status' => StatusValue::Cancelled->value,
        ]);
        Invoice::create([
            'direction' => 'payable', 'payee' => 'Davao Lubes', 'supplier_id' => $supplier->id,
            'issued_at' => '2026-07-01', 'due_at' => '2026-07-01', 'amount_cents' => 70_000,
            'status' => StatusValue::Pending->value,
        ]);

        $row = collect($this->actingAs($this->admin)->getJson('/api/v1/suppliers')->assertOk()->json('data'))
            ->firstWhere('id', $supplier->id);

        expect($row['service_spend_cents'])->toBe(320_000)
            ->and($row['billed_cents'])->toBe(70_000);
    });
});

describe('/fuel as the one source for fuel (fix 14)', function (): void {
    beforeEach(function (): void {
        $this->unit = ($this->vehicle)();
        $this->sheet = Truck::create([
            'label' => 'Truck 7', 'plate' => $this->unit->plate, 'vehicle_id' => $this->unit->id, 'position' => 7,
        ]);

        $this->log = fn (array $overrides = []) => $this->actingAs($this->admin)->postJson('/api/v1/fuel', [
            'vehicle_id' => $this->unit->id, 'litres' => 80, 'amount_cents' => 450_000,
            'odometer_km' => 184_000, 'receipt_no' => 'RC-'.random_int(1000, 9999),
            'logged_at' => '2026-07-16 08:30:00', 'status' => 'active', ...$overrides,
        ])->assertCreated()->json('data');

        $this->rowOn = fn (string $day) => LedgerEntry::query()
            ->where('truck_id', $this->sheet->id)->whereDate('date', $day)->first();
    });

    it('posts an active fill into its truck’s day, and counts it once', function (): void {
        ($this->log)();

        expect(($this->rowOn)('2026-07-16')->fuel_cents)->toBe(450_000);

        $period = ($this->period)('2026-07-01', '2026-07-31');
        $row = collect($period['trucks'])->firstWhere('truck.id', $this->sheet->id);

        expect($row['fuel_cents'])->toBe(450_000)
            ->and($row['fuel_log_cents'])->toBe(450_000)
            ->and($period['totals']['total_expenses_cents'])->toBe(450_000)
            ->and(($this->lines)('2026-07-01', '2026-07-31')['total_cents'])->toBe(450_000);
    });

    it('follows an edit, a moved day, a cancel and a delete', function (): void {
        $fill = ($this->log)();

        $this->actingAs($this->admin)->patchJson("/api/v1/fuel/{$fill['id']}", ['amount_cents' => 500_000])->assertOk();
        expect(($this->rowOn)('2026-07-16')->fuel_cents)->toBe(500_000);

        $this->actingAs($this->admin)->patchJson("/api/v1/fuel/{$fill['id']}", ['logged_at' => '2026-07-18 07:00:00'])->assertOk();
        expect(($this->rowOn)('2026-07-16')->fuel_cents)->toBe(0)
            ->and(($this->rowOn)('2026-07-18')->fuel_cents)->toBe(500_000);

        $this->actingAs($this->admin)->patchJson("/api/v1/fuel/{$fill['id']}", ['status' => 'cancelled'])->assertOk();
        expect(($this->rowOn)('2026-07-18')->fuel_cents)->toBe(0);

        $this->actingAs($this->admin)->patchJson("/api/v1/fuel/{$fill['id']}", ['status' => 'active'])->assertOk();
        $this->actingAs($this->admin)->deleteJson("/api/v1/fuel/{$fill['id']}")->assertNoContent();
        expect(($this->rowOn)('2026-07-18')->fuel_cents)->toBe(0)
            ->and(($this->period)('2026-07-01', '2026-07-31')['totals']['total_expenses_cents'])->toBe(0);
    });

    it('posts nothing for a pending request', function (): void {
        ($this->log)(['status' => 'pending']);

        expect(($this->rowOn)('2026-07-16'))->toBeNull();
    });

    it('refuses a typed fuel figure on a day fills have posted to', function (): void {
        ($this->log)();
        $row = ($this->rowOn)('2026-07-16');

        $this->actingAs($this->admin)->patchJson("/api/v1/ledger/{$row->id}", ['fuel_cents' => 999_000])
            ->assertStatus(422)->assertJsonValidationErrors('fuel_cents');
    });

    it('keeps a fill on a vehicle no truck points at as overhead', function (): void {
        $spare = ($this->vehicle)();
        ($this->log)(['vehicle_id' => $spare->id, 'amount_cents' => 200_000]);

        $totals = ($this->period)('2026-07-01', '2026-07-31')['totals'];

        expect(FuelRecord::query()->where('vehicle_id', $spare->id)->first()->posted_cents)->toBe(0)
            ->and($totals['overhead_cents'])->toBe(200_000)
            ->and($totals['total_expenses_cents'])->toBe(200_000);
    });

    it('backfills fills logged before posting, once, absorbing a typed duplicate on request', function (): void {
        LedgerEntry::create(['truck_id' => $this->sheet->id, 'date' => '2026-07-16', 'fuel_cents' => 450_000]);
        FuelRecord::create([
            'vehicle_id' => $this->unit->id, 'litres' => 80, 'amount_cents' => 450_000, 'odometer_km' => 1,
            'receipt_no' => 'RC-OLD', 'logged_at' => '2026-07-16 08:30:00', 'status' => 'active',
        ]);

        // Before: typed and logged, both counted.
        expect(($this->period)('2026-07-01', '2026-07-31')['totals']['total_expenses_cents'])->toBe(900_000);

        app(Kernel::class)->registerCommand(app(PostFuelFillsCommand::class));

        Artisan::call('cargo:post-fuel-fills', ['--absorb-typed' => true]);
        Artisan::call('cargo:post-fuel-fills', ['--absorb-typed' => true]);

        expect(($this->rowOn)('2026-07-16')->fuel_cents)->toBe(450_000)
            ->and(FuelRecord::query()->where('receipt_no', 'RC-OLD')->first()->posted_cents)->toBe(450_000)
            ->and(($this->period)('2026-07-01', '2026-07-31')['totals']['total_expenses_cents'])->toBe(450_000);
    });
});
