<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]);

    $this->supplier = Customer::create([
        'name' => 'Northern Mindanao Haulage',
        'contact' => '0917 222 0044',
    ]);

    $this->truck = Truck::create(['label' => 'Truck 1', 'plate' => 'MAR1390', 'position' => 1]);

    $this->haul = function (string $date = '2026-07-15', int $incomeCents = 10_000_000): LedgerEntry {
        return LedgerEntry::create([
            'truck_id' => $this->truck->getKey(),
            'date' => $date,
            'trip_income_cents' => $incomeCents,
            'fuel_cents' => 0,
            'driver_salary_cents' => 0,
            'helper_salary_cents' => 0,
            'maintenance_cents' => 0,
            'allowance_cents' => 0,
        ]);
    };

    $this->bill = fn (int $amountCents = 640_000, string $issued = '2026-07-01') => $this->actingAs($this->admin)
        ->postJson('/api/v1/billing', [
            'payee' => 'Northern Mindanao Haulage',
            'customer_id' => $this->supplier->id,
            'issued_at' => $issued,
            'due_at' => $issued,
            'amount_cents' => $amountCents,
            'direction' => 'payable',
        ])->assertCreated()->json('data');

    $this->payOut = fn (string $invoiceId, int $amountCents, string $paidOn) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payments', [
            'customer_id' => $this->supplier->id,
            'direction' => 'payable',
            'amount_cents' => $amountCents,
            'paid_on' => $paidOn,
            'method' => 'bank_transfer',
            'allocations' => [['invoice_id' => $invoiceId, 'amount_cents' => $amountCents]],
        ])->assertCreated();

    $this->quarter = fn (string $key = 'q3', int $year = 2026) => $this->actingAs($this->admin)
        ->getJson("/api/v1/finance/summary?year={$year}&quarter={$key}")
        ->assertOk()
        ->json('data.totals');
});

describe('the quarter total', function (): void {
    it('leaves a bill nobody has paid out of it', function (): void {
        ($this->haul)();
        ($this->bill)(640_000);

        $totals = ($this->quarter)();

        expect($totals['supplier_bills_cents'])->toBe(0)
            ->and($totals['net_income_cents'])->toBe(10_000_000);
    });

    it('takes a paid bill off the income', function (): void {
        ($this->haul)();
        $bill = ($this->bill)(640_000);
        ($this->payOut)($bill['id'], 640_000, '2026-07-20');

        $totals = ($this->quarter)();

        expect($totals['supplier_bills_cents'])->toBe(640_000)
            ->and($totals['total_expenses_cents'])->toBe(640_000)
            ->and($totals['net_income_cents'])->toBe(9_360_000);
    });

    it('counts only the part of a bill that has actually been paid', function (): void {
        ($this->haul)();
        $bill = ($this->bill)(640_000);
        ($this->payOut)($bill['id'], 240_000, '2026-07-20');

        expect(($this->quarter)()['supplier_bills_cents'])->toBe(240_000);
    });

    it('puts a June bill settled in July into July', function (): void {
        ($this->haul)('2026-07-15');
        ($this->haul)('2026-06-15');

        $bill = ($this->bill)(640_000, '2026-06-10');
        ($this->payOut)($bill['id'], 640_000, '2026-07-04');

        expect(($this->quarter)('q2')['supplier_bills_cents'])->toBe(0);
        expect(($this->quarter)('q3')['supplier_bills_cents'])->toBe(640_000);
    });

    it('leaves money paid to customers alone', function (): void {
        ($this->haul)();

        $invoice = $this->actingAs($this->admin)
            ->postJson('/api/v1/billing', [
                'customer_id' => $this->supplier->id,
                'issued_at' => '2026-07-01',
                'due_at' => '2026-07-31',
                'amount_cents' => 500_000,
                'direction' => 'receivable',
            ])->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->postJson('/api/v1/payments', [
                'customer_id' => $this->supplier->id,
                'direction' => 'receivable',
                'amount_cents' => 500_000,
                'paid_on' => '2026-07-20',
                'method' => 'bank_transfer',
                'allocations' => [['invoice_id' => $invoice['id'], 'amount_cents' => 500_000]],
            ])->assertCreated();

        expect(($this->quarter)()['supplier_bills_cents'])->toBe(0);
    });
});

describe('what is still owed, and what that leaves', function (): void {
    it('carries the unpaid bills beside the income rather than inside it', function (): void {
        ($this->haul)();
        ($this->bill)(640_000);

        $totals = ($this->quarter)();

        expect($totals['total_expenses_cents'])->toBe(0)
            ->and($totals['net_income_cents'])->toBe(10_000_000)
            ->and($totals['payables_cents'])->toBe(640_000)
            ->and($totals['actual_income_cents'])->toBe(9_360_000);
    });

    it('stops counting a bill as owed once it has been paid', function (): void {
        ($this->haul)();
        $bill = ($this->bill)(640_000);
        ($this->payOut)($bill['id'], 640_000, '2026-07-20');

        $totals = ($this->quarter)();

        expect($totals['payables_cents'])->toBe(0)
            ->and($totals['supplier_bills_cents'])->toBe(640_000)
            ->and($totals['net_income_cents'])->toBe(9_360_000)
            ->and($totals['actual_income_cents'])->toBe(9_360_000);
    });

    it('still owes the part of a bill that is unpaid', function (): void {
        ($this->haul)();
        $bill = ($this->bill)(640_000);
        ($this->payOut)($bill['id'], 240_000, '2026-07-20');

        $totals = ($this->quarter)();

        expect($totals['supplier_bills_cents'])->toBe(240_000)
            ->and($totals['payables_cents'])->toBe(400_000)
            ->and($totals['net_income_cents'])->toBe(9_760_000)
            ->and($totals['actual_income_cents'])->toBe(9_360_000);
    });

    it('asks the question as at the end of the period, not as at today', function (): void {
        ($this->haul)('2026-04-15');

        ($this->bill)(640_000, '2026-07-01');

        expect(($this->quarter)('q2')['payables_cents'])->toBe(0);
        expect(($this->quarter)('q3')['payables_cents'])->toBe(640_000);
    });

    it('counts a partner the fleet has not paid yet', function (): void {
        ($this->haul)();

        WalletEntry::create([
            'trucker_id' => Trucker::factory()->approved()->create()->getKey(),
            'kind' => WalletEntryKind::Earning->value,
            'status' => StatusValue::Paid->value,
            'amount_cents' => 880_000,
            'occurred_on' => '2026-07-10',
        ]);

        expect(($this->quarter)()['payables_cents'])->toBe(880_000);
    });

    it('leaves a partner who owes the fleet out of it', function (): void {
        ($this->haul)();

        WalletEntry::create([
            'trucker_id' => Trucker::factory()->approved()->create()->getKey(),
            'kind' => WalletEntryKind::Commission->value,
            'status' => StatusValue::Paid->value,
            'amount_cents' => -120_000,
            'occurred_on' => '2026-07-10',
        ]);

        expect(($this->quarter)()['payables_cents'])->toBe(0)
            ->and(($this->quarter)()['actual_income_cents'])->toBe(10_000_000);
    });
});

describe('paying the truckers', function (): void {
    beforeEach(function (): void {
        ($this->haul)();

        $this->partner = Trucker::factory()->approved()->create(['name' => 'Boyet Aquino']);

        $this->owed = fn (int $cents = 880_000, ?string $truckerId = null) => WalletEntry::create([
            'trucker_id' => $truckerId ?? $this->partner->getKey(),
            'kind' => WalletEntryKind::Earning->value,
            'status' => StatusValue::Paid->value,
            'amount_cents' => $cents,
            'occurred_on' => '2026-07-10',
        ]);

        $this->hiredTruck = fn (Trucker $owner) => Vehicle::create([
            'plate' => 'HIRE-'.fake()->unique()->numberBetween(1000, 9999),
            'model' => 'Isuzu Forward',
            'registration_no' => fake()->unique()->bothify('REG-####'),
            'capacity_kg' => 15_000,
            'status' => StatusValue::Available->value,
            'arrangement' => VehicleArrangement::RentedShare->value,
            'share_bp' => 1500,
            'owner_trucker_id' => $owner->getKey(),
        ]);

        $this->paid = fn (
            int $cents = 880_000,
            string $on = '2026-07-20',
            string $status = 'paid',
            ?string $truckerId = null,
        ) => WalletEntry::create([
            'trucker_id' => $truckerId ?? $this->partner->getKey(),
            'kind' => WalletEntryKind::Payout->value,
            'status' => $status,
            'amount_cents' => -$cents,
            'occurred_on' => $on,
        ]);
    });

    it('treats a partner\'s share as theirs: not income, not a cost, before or after paying it', function (): void {
        ($this->owed)();

        $before = ($this->quarter)();

        expect($before['payables_cents'])->toBe(880_000)
            ->and($before['held_for_partners_cents'])->toBe(880_000)
            ->and($before['trucker_payouts_cents'])->toBe(0)
            ->and($before['net_income_cents'])->toBe(10_000_000)
            ->and($before['actual_income_cents'])->toBe(10_000_000);

        ($this->paid)();

        $after = ($this->quarter)();

        expect($after['payables_cents'])->toBe(0)
            ->and($after['held_for_partners_cents'])->toBe(0)
            ->and($after['trucker_payouts_cents'])->toBe(880_000)
            ->and($after['total_expenses_cents'])->toBe(0)
            ->and($after['net_income_cents'])->toBe(10_000_000)
            ->and($after['actual_income_cents'])->toBe(10_000_000);
    });

    it('waits for the money to land before counting it', function (): void {
        ($this->owed)();
        ($this->paid)(880_000, '2026-07-20', 'pending');

        $totals = ($this->quarter)();

        expect($totals['trucker_payouts_cents'])->toBe(0)
            ->and($totals['payables_cents'])->toBe(880_000);
    });

    it('counts it in the quarter the money was handed over', function (): void {
        ($this->owed)();
        ($this->paid)(880_000, '2026-10-05');

        expect(($this->quarter)('q3')['trucker_payouts_cents'])->toBe(0);
        expect(($this->quarter)('q4')['trucker_payouts_cents'])->toBe(880_000);
    });

    it('charges it to the period rather than to a truck', function (): void {
        ($this->owed)();
        ($this->paid)();

        $body = $this->actingAs($this->admin)
            ->getJson('/api/v1/finance/summary?year=2026&quarter=q3')
            ->assertOk()
            ->json('data');

        $truck = collect($body['trucks'])->firstWhere('truck.plate', 'MAR1390');

        expect($truck['total_expenses_cents'])->toBe(0)
            ->and($body['totals']['trucker_payouts_cents'])->toBe(880_000);
    });

    it('does not charge a revenue-share truck owner twice', function (): void {
        $owner = Trucker::factory()->approved()->create(['name' => 'Delfin Uy']);

        ($this->hiredTruck)($owner);

        ($this->owed)(850_000, $owner->getKey());
        ($this->paid)(850_000, '2026-07-20', 'paid', $owner->getKey());

        expect(($this->quarter)()['trucker_payouts_cents'])->toBe(0);
    });

    it('still counts a partner who happens to haul alongside them', function (): void {
        $owner = Trucker::factory()->approved()->create(['name' => 'Delfin Uy']);

        ($this->hiredTruck)($owner);

        ($this->paid)(850_000, '2026-07-20', 'paid', $owner->getKey());
        ($this->paid)(880_000);

        expect(($this->quarter)()['trucker_payouts_cents'])->toBe(880_000);
    });

    it('drops it into the day the money left, on Sales', function (): void {
        ($this->owed)();
        ($this->paid)(880_000, '2026-07-20');

        $body = $this->actingAs($this->admin)
            ->getJson('/api/v1/finance/sales?granularity=daily&from=2026-07-01&to=2026-07-31')
            ->assertOk()
            ->json('data');

        expect(collect($body['series'])->firstWhere('key', '2026-07-20'))->toBeNull()
            ->and($body['totals']['expenses_cents'])->toBe(0);
    });
});

describe('the screens that read it', function (): void {
    beforeEach(function (): void {
        ($this->haul)('2026-07-15');

        $bill = ($this->bill)(640_000, '2026-07-01');
        ($this->payOut)($bill['id'], 640_000, '2026-07-15');
    });

    it('charges it to the period rather than to a truck', function (): void {
        $body = $this->actingAs($this->admin)
            ->getJson('/api/v1/finance/summary?year=2026&quarter=q3')
            ->assertOk()
            ->json('data');

        $truck = collect($body['trucks'])->firstWhere('truck.plate', 'MAR1390');

        expect($truck['total_expenses_cents'])->toBe(0)
            ->and($body['totals']['supplier_bills_cents'])->toBe(640_000);
    });

    it('shows the same figure on Profitability for the same days', function (): void {
        $totals = $this->actingAs($this->admin)
            ->getJson('/api/v1/finance/profitability?from=2026-07-10&to=2026-07-20')
            ->assertOk()
            ->json('data.totals');

        expect($totals['supplier_bills_cents'])->toBe(640_000)
            ->and($totals['net_income_cents'])->toBe(9_360_000);
    });

    it('drops it into the week the money left, on Sales', function (): void {
        $body = $this->actingAs($this->admin)
            ->getJson('/api/v1/finance/sales?granularity=daily&from=2026-07-01&to=2026-07-31')
            ->assertOk()
            ->json('data');

        $day = collect($body['series'])->firstWhere('key', '2026-07-15');

        expect($day['expenses_cents'])->toBe(640_000)
            ->and($body['totals']['expenses_cents'])->toBe(640_000)
            ->and($body['totals']['net_cents'])->toBe(9_360_000);
    });
});
