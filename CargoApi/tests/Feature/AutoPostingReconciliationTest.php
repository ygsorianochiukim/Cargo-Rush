<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Services\AutoPostingService;
use App\Domain\Accounting\Services\FinancialStatementService;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Services\PaymentService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Services\FinanceService;
use App\Domain\Finance\Services\PayablesService;
use App\Domain\Finance\Services\PayrollCostService;
use App\Domain\Finance\Services\ReceivablesService;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerVehicle;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Trucker\Services\WalletService;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The statements say what the Finance screens say.
 *
 * The guard on auto-posting. One realistic quarter — every kind of record the
 * roll-up counts, and the awkward cases it deliberately does not — is built
 * through the same doors the office uses, and then the books and the screens
 * are held against each other to the centavo:
 *
 *   income statement revenue    = the roll-up's total income
 *   income statement expenses   = the roll-up's total expenses (payroll in)
 *   1100 Accounts receivable    = what Billing says customers owe
 *   2010 Accounts payable       = the Payables page's supplier bills
 *   2020 − 1160                 = every partner's wallet, netted
 *   2160 + 2200                 = payroll's withholdings still to remit
 *
 * and the balance sheet balances, a correction voids and reposts, re-syncing
 * changes nothing, and a backfill run twice adds nothing.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);

    // The books first, so everything after posts as it is written.
    app(CompanyProvisioner::class)->provision($this->company);
    $this->seed(FleetSeeder::class);

    $this->travelTo(Carbon::parse('2026-08-12 10:00:00'));

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->driver = Driver::query()->firstOrFail();

    // A customer who withholds 2% EWT on the net.
    $this->customer = Customer::create([
        'name' => 'Pryce Gases', 'contact' => '0917 000 1111',
        'withholds_tax' => true, 'withholding_rate_bp' => 200,
    ]);

    $this->unit = fn (array $terms = []) => Vehicle::create([
        'plate' => 'REC-'.fake()->unique()->numberBetween(1000, 9999),
        'model' => 'Isuzu Forward',
        'registration_no' => fake()->unique()->bothify('REG-####'),
        'capacity_kg' => 15_000,
        'status' => StatusValue::Available->value,
        ...$terms,
    ]);

    /** A company run, booked, checked and delivered. */
    $this->haul = function (Vehicle $vehicle, int $price): Trip {
        $trip = Trip::create([
            'customer_id' => $this->customer->getKey(), 'origin' => 'Iponan', 'destination' => 'Bukidnon',
            'cargo' => 'Rice', 'weight_kg' => 10_000, 'status' => StatusValue::Assigned->value,
            'scheduled_at' => now()->addHour(), 'price_cents' => $price, 'currency' => 'PHP',
            'vehicle_id' => $vehicle->getKey(), 'driver_id' => $this->driver->getKey(),
        ]);

        $this->passPreTripCheck($trip->getKey());

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/complete", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();

        return $trip->refresh();
    };

    /** A partner's run — the desk brokered it, or the customer picked the partner. */
    $this->partnerRun = function (Trucker $trucker, User $user, bool $direct, int $price): Trip {
        $trip = Trip::create([
            'customer_id' => $this->customer->getKey(), 'origin' => 'Malanang', 'destination' => 'Mambuaya',
            'cargo' => 'Goods', 'weight_kg' => 1_800, 'status' => StatusValue::Pending->value,
            'scheduled_at' => now()->addHour(), 'price_cents' => $price, 'currency' => 'PHP',
            ...($direct ? ['trucker_id' => $trucker->id] : []),
        ]);

        if ($direct) {
            $this->actingAs($user)->postJson("/api/v1/partner/jobs/{$trip->id}/accept")->assertCreated();
        } else {
            $this->actingAs($this->admin)
                ->postJson("/api/v1/trips/{$trip->id}/assign-trucker", ['trucker_id' => $trucker->id])
                ->assertOk();
        }

        $this->actingAs($user)->postJson("/api/v1/partner/trips/{$trip->id}/start")->assertOk();
        $this->actingAs($user)->postJson("/api/v1/partner/trips/{$trip->id}/deliver", ['receiver_name' => 'Mrs Uy'])->assertOk();

        return $trip->refresh();
    };

    $this->bill = fn (int $cents, string $issued, ?string $supplierId = null) => $this->actingAs($this->admin)
        ->postJson('/api/v1/billing', [
            'payee' => 'Davao Lubes & Parts',
            'supplier_id' => $supplierId,
            'issued_at' => $issued,
            'due_at' => $issued,
            'amount_cents' => $cents,
            'direction' => 'payable',
        ])->assertCreated()->json('data');

    $this->pay = fn (string $invoiceId, int $cents, string $on, string $direction) => app(PaymentService::class)->record(
        ['customer_id' => Invoice::find($invoiceId)->customer_id, 'direction' => $direction, 'amount_cents' => $cents,
            'paid_on' => $on, 'method' => 'bank_transfer', 'currency' => 'PHP'],
        [['invoice_id' => $invoiceId, 'amount_cents' => $cents]],
    );

    $this->spend = fn (string $key, string $date, int $cents, array $extra = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/expenses', [
            'category_id' => ExpenseCategory::where('key', $key)->firstOrFail()->id,
            'date' => $date,
            'amount_cents' => $cents,
            ...$extra,
        ])->assertCreated()->json('data');

    /** An account's balance from posted entries up to a day, debit-positive. */
    $this->balance = fn (string $code, string $asOf = '2026-09-30') => (int) JournalLine::query()
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
        ->where('journal_entries.status', JournalEntry::POSTED)
        ->whereDate('journal_entries.entry_date', '<=', $asOf)
        ->where('journal_lines.account_id', Account::where('code', $code)->value('id'))
        ->sum(DB::raw('journal_lines.debit_cents - journal_lines.credit_cents'));
});

it('keeps the books and the Finance screens in agreement over a whole quarter', function (): void {
    /* ---------------------------------------------------------------- Income */

    // 1. A company truck's run, invoiced on delivery, then paid less its EWT.
    $own = ($this->unit)();
    $ownTrip = ($this->haul)($own, 1_000_000);
    $ownInvoice = Invoice::query()->where('trip_id', $ownTrip->getKey())->firstOrFail();

    expect((int) $ownInvoice->withholding_cents)->toBeGreaterThan(0);

    // The office types the day's costs onto the sheet the run opened.
    $ownDay = LedgerEntry::query()->where('trip_id', $ownTrip->getKey())->firstOrFail();
    $this->actingAs($this->admin)->patchJson("/api/v1/ledger/{$ownDay->id}", [
        'driver_salary_cents' => 150_000,
        'allowance_cents' => 30_000,
    ])->assertOk();

    ($this->pay)($ownInvoice->id, $ownInvoice->dueCents(), '2026-08-25', 'receivable');

    // 2. A revenue-share truck: the owner's 85% is a cost of the fleet's run.
    $ownerUser = User::factory()->create(['role' => Role::Trucker->value, 'company_id' => $this->company->getKey()]);
    $owner = Trucker::factory()->approved()->create(['name' => 'Delfin Uy', 'licence_no' => null, 'user_id' => $ownerUser->getKey()]);
    $shared = ($this->unit)([
        'arrangement' => VehicleArrangement::RentedShare->value, 'wheels' => 10, 'owner_name' => 'Delfin Uy',
        'owner_trucker_id' => $owner->getKey(), 'share_bp' => 1500,
    ]);
    ($this->haul)($shared, 800_000);

    // 3 & 4. A partner's run the desk billed, and one the partner billed.
    $partnerUser = User::factory()->create(['role' => Role::Trucker->value, 'company_id' => $this->company->getKey()]);
    $partner = Trucker::factory()->approved()->create(['user_id' => $partnerUser->getKey()]);
    TruckerVehicle::factory()->create(['trucker_id' => $partner->id, 'capacity_kg' => 20_000, 'status' => 'available']);

    ($this->partnerRun)($partner, $partnerUser, false, 500_000);
    ($this->partnerRun)($partner, $partnerUser, true, 500_000);

    // Paying the partner everything nets the direct run's commission off.
    app(WalletService::class)->payOut($partner, occurredOn: Carbon::parse('2026-08-28'), cleared: true);

    // 5. An invoice raised by hand — other income — and one cancelled.
    $manual = $this->actingAs($this->admin)->postJson('/api/v1/billing', [
        'customer_id' => $this->customer->id, 'issued_at' => '2026-08-05', 'due_at' => '2026-09-05',
        'amount_cents' => 250_000, 'direction' => 'receivable',
    ])->assertCreated()->json('data');

    $cancelled = $this->actingAs($this->admin)->postJson('/api/v1/billing', [
        'customer_id' => $this->customer->id, 'issued_at' => '2026-08-06', 'due_at' => '2026-09-06',
        'amount_cents' => 400_000, 'direction' => 'receivable',
    ])->assertCreated()->json('data');
    Invoice::findOrFail($cancelled['id'])->update(['status' => StatusValue::Cancelled->value]);

    /* -------------------------------------------------------------- Expenses */

    ($this->spend)('office', '2026-07-10', 1_200_000);
    ($this->spend)('toll-parking', '2026-08-12', 45_000);
    $gone = ($this->spend)('food', '2026-08-13', 60_000);
    $this->actingAs($this->admin)->deleteJson("/api/v1/expenses/{$gone['id']}")->assertSuccessful();

    // A fill on the company truck (posts into its Fuel column) and one on a
    // unit no truck points at (fleet overhead).
    FuelRecord::create([
        'vehicle_id' => $own->id, 'litres' => 100, 'amount_cents' => 450_000, 'odometer_km' => 1,
        'receipt_no' => 'RC-1', 'logged_at' => '2026-08-12 15:00:00', 'status' => 'active',
    ]);
    $spare = ($this->unit)();
    FuelRecord::create([
        'vehicle_id' => $spare->id, 'litres' => 40, 'amount_cents' => 180_000, 'odometer_km' => 1,
        'receipt_no' => 'RC-2', 'logged_at' => '2026-08-20 09:00:00', 'status' => 'active',
    ]);

    // A service with no bill, and one the garage billed — its cost is the
    // job's, so paying the bill adds nothing. Plus a bill for something
    // else, paid: that one is an expense on the day it was paid.
    $garage = Supplier::factory()->create(['name' => 'Davao Lubes & Parts']);
    $this->actingAs($this->admin)->postJson("/api/v1/vehicles/{$own->id}/maintenance", [
        'kind' => 'Oil change', 'due_at' => '2026-08-14', 'completed_on' => '2026-08-14', 'cost_cents' => 320_000,
    ])->assertCreated();

    $garageBill = ($this->bill)(540_000, '2026-08-16', $garage->id);
    $this->actingAs($this->admin)->postJson("/api/v1/vehicles/{$own->id}/maintenance", [
        'kind' => 'Brake job', 'due_at' => '2026-08-16', 'completed_on' => '2026-08-16', 'cost_cents' => 540_000,
        'supplier_id' => $garage->id, 'invoice_id' => $garageBill['id'],
    ])->assertCreated();
    ($this->pay)($garageBill['id'], 540_000, '2026-09-02', 'payable');

    $tyres = ($this->bill)(900_000, '2026-08-18');
    ($this->pay)($tyres['id'], 600_000, '2026-09-03', 'payable');
    ($this->bill)(210_000, '2026-09-10');

    /* --------------------------------------------------------------- Payroll */

    $hire = fn (string $first, string $position) => $this->actingAs($this->admin)->postJson('/api/v1/employees', [
        'first_name' => $first, 'last_name' => 'Reyes', 'position' => $position, 'department' => 'Operations',
        'contact' => '0917 555 0'.random_int(100, 999), 'hired_on' => '2026-01-05', 'amount_cents' => 3_000_000,
    ])->assertCreated()->json('data');

    $hire('Elena', 'Office Staff');
    // The run's driver, whose ₱1,500 on the sheet is part of the same pay.
    Employee::findOrFail($hire('Marco', 'Driver')['id'])->update(['driver_id' => $this->driver->getKey()]);

    $run = $this->actingAs($this->admin)->postJson('/api/v1/payroll', [
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'pay_date' => '2026-08-15',
    ])->assertCreated()->json('data');
    $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();
    $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/pay")->assertOk();

    /* ------------------------------------------------------ Hold them together */

    $this->travelTo(Carbon::parse('2026-09-20 17:00:00'));

    $from = Carbon::parse('2026-07-01');
    $to = Carbon::parse('2026-09-30');

    $rollup = app(FinanceService::class)->periodRollup($from, $to)['totals'];
    $statement = app(FinancialStatementService::class)->incomeStatement($from, $to);

    expect($rollup['total_income_cents'])->toBeGreaterThan(0)
        ->and($rollup['payroll_cents'])->toBe(app(PayrollCostService::class)->costBetween($from, $to))
        ->and($statement['revenue']['total_cents'])->toBe($rollup['total_income_cents'])
        ->and($statement['expenses']['total_cents'])->toBe($rollup['total_expenses_cents'])
        ->and($statement['net_income_cents'])->toBe($rollup['net_income_cents']);

    // VAT is beside the income, never in it.
    expect(-($this->balance)('2150'))->toBe($rollup['vat_collected_cents']);

    // What customers owe.
    $invoicesOwed = collect(app(ReceivablesService::class)->linesAsOf($to))->where('source', 'invoice')->sum('amount_cents');
    expect(($this->balance)('1100'))->toBe((int) $invoicesOwed);

    // What the fleet owes.
    $payables = collect(app(PayablesService::class)->overview()['groups'])->keyBy('key');
    expect(-($this->balance)('2010'))->toBe($payables['supplier_bills']['total_cents'])
        ->and(-($this->balance)('2020') - ($this->balance)('1160'))->toBe((int) WalletEntry::query()->landed()->sum('amount_cents'))
        ->and(-($this->balance)('2160') - ($this->balance)('2200'))->toBe($payables['payroll']['total_cents']);

    // And the sheet balances.
    expect(app(FinancialStatementService::class)->balanceSheet($to)['balanced'])->toBeTrue();

    /* -------------------------------------------- Corrections, and re-running */

    // A save that changes nothing posts nothing.
    $before = JournalEntry::query()->count();
    $this->actingAs($this->admin)->patchJson("/api/v1/ledger/{$ownDay->id}", ['remarks' => 'Tyre looked soft'])->assertOk();
    expect(JournalEntry::query()->count())->toBe($before);

    // A correction voids the day's posting and posts the new one.
    $this->actingAs($this->admin)->patchJson("/api/v1/ledger/{$ownDay->id}", ['allowance_cents' => 40_000])->assertOk();

    $sheetEntries = JournalEntry::query()
        ->where('source_type', $ownDay->getMorphClass())->where('source_id', $ownDay->id)->get();

    expect($sheetEntries->where('status', JournalEntry::POSTED))->toHaveCount(1)
        ->and($sheetEntries->where('status', JournalEntry::VOID)->count())->toBeGreaterThanOrEqual(1);

    $rollup = app(FinanceService::class)->periodRollup($from, $to)['totals'];
    expect(app(FinancialStatementService::class)->incomeStatement($from, $to)['expenses']['total_cents'])
        ->toBe($rollup['total_expenses_cents']);

    // Every record synced again: nothing to do.
    $service = app(AutoPostingService::class);
    $changes = 0;

    foreach (AutoPostingService::sources() as $class) {
        foreach ($class::query()->get() as $record) {
            $result = $service->sync($record);
            $changes += $result['posted'] + $result['voided'];
        }
    }

    expect($changes)->toBe(0);

    // And a backfill, twice, adds nothing either.
    $count = JournalEntry::query()->count();
    $this->artisan('cargo:accounting-backfill')->assertSuccessful();
    $this->artisan('cargo:accounting-backfill')->assertSuccessful();

    expect(JournalEntry::query()->count())->toBe($count);
});
