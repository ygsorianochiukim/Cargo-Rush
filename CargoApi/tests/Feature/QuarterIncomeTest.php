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

/**
 * What a quarter actually came to, once the suppliers are paid.
 *
 * A bill raised against the fleet in Billing used to be in none of the period
 * reports. It is not one of the workbook's five ledger columns and it is not an
 * `Expense` row, so the Quarterly Summary showed the takings less what the
 * trucks cost and left the suppliers out of it — and the office read a net
 * income the bank did not agree with.
 *
 * These pin the rule that fixes it, and the rule is a **cash** one among
 * accruals, which is the part worth being explicit about:
 *
 *   money allocated to a payable invoice, by a payment dated inside the window
 *
 * So an unpaid bill counts nothing, a half-paid bill counts half, and a bill
 * raised in one quarter and settled in the next belongs to the next. Each of
 * those is a way of being wrong that would look perfectly reasonable on screen,
 * which is why all three are here.
 *
 * The accrual view of the same money is the income statement under Accounting.
 * It is a different report for a different reader and is not touched.
 */
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

    /** ₱100,000 of hauling, on a day in the third quarter. */
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

    /** A bill from a supplier. Raised, not paid. */
    $this->bill = fn (int $amountCents = 640_000, string $issued = '2026-07-01') => $this->actingAs($this->admin)
        ->postJson('/api/v1/billing', [
            'payee' => 'Northern Mindanao Haulage',
            'customer_id' => $this->supplier->id,
            'issued_at' => $issued,
            'due_at' => $issued,
            'amount_cents' => $amountCents,
            'direction' => 'payable',
        ])->assertCreated()->json('data');

    /** Money out, against that bill, on a stated day. */
    $this->payOut = fn (string $invoiceId, int $amountCents, string $paidOn) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payments', [
            'customer_id' => $this->supplier->id,
            'direction' => 'payable',
            'amount_cents' => $amountCents,
            'paid_on' => $paidOn,
            'method' => 'bank_transfer',
            'allocations' => [['invoice_id' => $invoiceId, 'amount_cents' => $amountCents]],
        ])->assertCreated();

    /** The Quarterly Summary, as the page reads it. */
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

        // A commitment is not money that has left. The figure this page exists
        // to give is what the quarter actually came to.
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

        // The allocations are the money. The face value of the document is
        // what was asked for, which is a different question.
        expect(($this->quarter)()['supplier_bills_cents'])->toBe(240_000);
    });

    it('puts a June bill settled in July into July', function (): void {
        ($this->haul)('2026-07-15');
        ($this->haul)('2026-06-15');

        $bill = ($this->bill)(640_000, '2026-06-10');
        ($this->payOut)($bill['id'], 640_000, '2026-07-04');

        // The day it left the bank is the only date on which it is true.
        expect(($this->quarter)('q2')['supplier_bills_cents'])->toBe(0);
        expect(($this->quarter)('q3')['supplier_bills_cents'])->toBe(640_000);
    });

    it('leaves money paid to customers alone', function (): void {
        ($this->haul)();

        // A receivable settled is money coming *in*, already counted as trip
        // income when the run was delivered. Counting it here as well would
        // take the quarter's own takings off its own takings.
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

        // An unpaid bill has cost the quarter nothing, so it is in neither the
        // expenses nor the net. It is in `payables_cents`, and the subtraction
        // is the figure an office actually decides on.
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

        // The same money, and it has moved across: out of what is owed and
        // into what the quarter cost. Counted in both places it would be
        // deducted twice, which is the failure this pins.
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

        // ₱100,000 earned, ₱2,400 of the bill paid, ₱4,000 of it still to find.
        // The quarter cost ₱2,400 and is carrying ₱4,000, and the two figures
        // are not the same question.
        expect($totals['supplier_bills_cents'])->toBe(240_000)
            ->and($totals['payables_cents'])->toBe(400_000)
            ->and($totals['net_income_cents'])->toBe(9_760_000)
            ->and($totals['actual_income_cents'])->toBe(9_360_000);
    });

    it('asks the question as at the end of the period, not as at today', function (): void {
        ($this->haul)('2026-04-15');

        // Raised in July. The second quarter closed in June owing nothing, and
        // a card that showed it burdened with this would be describing no
        // period at all.
        ($this->bill)(640_000, '2026-07-01');

        expect(($this->quarter)('q2')['payables_cents'])->toBe(0);
        expect(($this->quarter)('q3')['payables_cents'])->toBe(640_000);
    });

    it('counts a partner the fleet has not paid yet', function (): void {
        ($this->haul)();

        // The other kind of debt on the Payables screen, and the one an office
        // is most likely to forget: money owed to somebody who has already
        // hauled the load.
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

        // A negative wallet is the money going the other way — the partner
        // billed the customer directly and owes the fleet its cut. It belongs
        // on a receivables list, and netting it off here would have an unpaid
        // debt *raise* the quarter's actual income.
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

        /** A run they hauled and are owed for, on a day in the quarter. */
        $this->owed = fn (int $cents = 880_000, ?string $truckerId = null) => WalletEntry::create([
            'trucker_id' => $truckerId ?? $this->partner->getKey(),
            'kind' => WalletEntryKind::Earning->value,
            'status' => StatusValue::Paid->value,
            'amount_cents' => $cents,
            'occurred_on' => '2026-07-10',
        ]);

        /**
         * A truck the fleet hired on a revenue share, owned by this partner.
         *
         * `Vehicle::create` rather than a factory, as `HiredTruckTest` does:
         * the point is the terms, and a factory would invent the rest.
         */
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

        /** The fleet handing the money over. Negative — it is money leaving. */
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

    it('does not let paying a partner make the quarter look better', function (): void {
        ($this->owed)();

        // Owed but unpaid: a claim against the quarter, not a cost of it.
        $before = ($this->quarter)();

        expect($before['payables_cents'])->toBe(880_000)
            ->and($before['trucker_payouts_cents'])->toBe(0)
            ->and($before['net_income_cents'])->toBe(10_000_000)
            ->and($before['actual_income_cents'])->toBe(9_120_000);

        ($this->paid)();

        // Paid: the claim is gone and the money is gone with it. This is the
        // whole point — the figure used to spring back to ₱100,000 the moment
        // the office settled up, and an office that paid three partners on
        // Friday read a healthier quarter on Monday.
        $after = ($this->quarter)();

        expect($after['payables_cents'])->toBe(0)
            ->and($after['trucker_payouts_cents'])->toBe(880_000)
            ->and($after['total_expenses_cents'])->toBe(880_000)
            ->and($after['net_income_cents'])->toBe(9_120_000)
            ->and($after['actual_income_cents'])->toBe(9_120_000);
    });

    it('waits for the money to land before counting it', function (): void {
        ($this->owed)();
        ($this->paid)(880_000, '2026-07-20', 'pending');

        // A transfer the office has started and the bank has not moved on. It
        // is still owed and it has not yet cost the quarter anything —
        // counting it in both places would deduct it twice.
        $totals = ($this->quarter)();

        expect($totals['trucker_payouts_cents'])->toBe(0)
            ->and($totals['payables_cents'])->toBe(880_000);
    });

    it('counts it in the quarter the money was handed over', function (): void {
        ($this->owed)();
        ($this->paid)(880_000, '2026-10-05');

        // Earned in the third quarter, paid in the fourth. The day it left the
        // bank is the only date on which it is true.
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

        // A partner's run has no sheet of its own, so there is no unit to
        // charge it to — and loading it onto one would make that truck look
        // unprofitable for a haul it never made.
        $truck = collect($body['trucks'])->firstWhere('truck.plate', 'MAR1390');

        expect($truck['total_expenses_cents'])->toBe(0)
            ->and($body['totals']['trucker_payouts_cents'])->toBe(880_000);
    });

    it('does not charge a revenue-share truck owner twice', function (): void {
        // The exclusion that keeps the figure honest. A run on a truck the
        // fleet hired on a share writes the owner's cut to the daily sheet as
        // `owner_share_cents` the day it is delivered — so the cost is already
        // counted, and paying the owner afterwards settles a debt the books
        // have recognised rather than incurring a new one.
        $owner = Trucker::factory()->approved()->create(['name' => 'Delfin Uy']);

        ($this->hiredTruck)($owner);

        ($this->owed)(850_000, $owner->getKey());
        ($this->paid)(850_000, '2026-07-20', 'paid', $owner->getKey());

        expect(($this->quarter)()['trucker_payouts_cents'])->toBe(0);
    });

    it('still counts a partner who happens to haul alongside them', function (): void {
        // The exclusion is per partner, not a switch on the whole figure: a
        // fleet running both arrangements must still see what it paid the
        // owner-operators.
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

        expect(collect($body['series'])->firstWhere('key', '2026-07-20')['expenses_cents'])
            ->toBe(880_000)
            ->and($body['totals']['expenses_cents'])->toBe(880_000);
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

        // A supplier bill is not any unit's cost, and loading it onto one would
        // make that truck look unprofitable for a bill it never incurred. It
        // sits in the totals and in no row, exactly as the overhead does.
        $truck = collect($body['trucks'])->firstWhere('truck.plate', 'MAR1390');

        expect($truck['total_expenses_cents'])->toBe(0)
            ->and($body['totals']['supplier_bills_cents'])->toBe(640_000);
    });

    it('shows the same figure on Profitability for the same days', function (): void {
        $totals = $this->actingAs($this->admin)
            ->getJson('/api/v1/finance/profitability?from=2026-07-10&to=2026-07-20')
            ->assertOk()
            ->json('data.totals');

        // One roll-up behind both pages, which is the point of
        // `FinanceService::periodRollup` — two assemblies of the same three
        // pieces is two chances to leave one out.
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
