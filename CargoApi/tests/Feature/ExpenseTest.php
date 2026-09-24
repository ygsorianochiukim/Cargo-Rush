<?php

declare(strict_types=1);

use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Supplier\Models\Supplier;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\NavigationSeeder;

/**
 * Other Expenses, and how it reaches the rest of Finance.
 *
 * The behaviour worth pinning is the boundary. Categorised spend is *additive*
 * to the workbook's five columns, it attaches itself to the day's sheet so the
 * two views cannot disagree, and spend belonging to no truck still has to
 * reach the period total — which is the case a `groupBy` silently drops.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(ExpenseCategorySeeder::class);

    $this->accountant = User::factory()->create(['role' => Role::Accountant]);

    $this->truck = Truck::create(['label' => 'Truck 1', 'plate' => 'NCR 4412', 'position' => 1]);
    $this->food = ExpenseCategory::where('key', 'food')->firstOrFail();
    $this->office = ExpenseCategory::where('key', 'office')->firstOrFail();

    $this->file = fn (array $payload) => $this->actingAs($this->accountant)
        ->postJson('/api/v1/expenses', [
            'category_id' => $this->food->id,
            'date' => now()->toDateString(),
            'amount_cents' => 45_000,
            ...$payload,
        ]);
});

describe('filing an expense', function (): void {
    it('records it against a category and who it was bought from', function (): void {
        $supplier = Supplier::factory()->create(['name' => 'Aling Nena Carinderia']);

        $response = ($this->file)(['supplier_id' => $supplier->id])->assertCreated();

        expect($response->json('data.category_name'))->toBe('Food');
        expect($response->json('data.amount_cents'))->toBe(45_000);
        expect($response->json('data.status'))->toBe('active');
        expect(Expense::firstOrFail()->supplier_id)->toBe($supplier->id);
    });

    it('takes no truck, because what a unit costs is not filed here', function (): void {
        /**
         * The form lost its truck and vehicle pickers, and this is the test
         * that says so on purpose rather than by the absence of another.
         *
         * An oil change, a repair, a set of tyres is a **maintenance job** on
         * the unit now, and what it cost reaches that truck's Maintenance
         * column on the daily sheet. What is left on this screen is the spend
         * that belongs to the period rather than to any one unit: meals,
         * tarpaulins, tolls, the office rent.
         *
         * A payload naming a truck is not refused, it is ignored — the field is
         * simply not in the rules, so it never reaches the column.
         */
        $response = ($this->file)(['truck_id' => $this->truck->id])->assertCreated();

        expect($response->json('data.truck_id'))->toBeNull();
        expect($response->json('data.ledger_entry_id'))->toBeNull();
        expect(LedgerEntry::count())->toBe(0);
    });

    it('leaves overhead unattached, because it belongs to no unit', function (): void {
        $response = ($this->file)(['category_id' => $this->office->id, 'amount_cents' => 1_200_000])
            ->assertCreated();

        expect($response->json('data.truck_id'))->toBeNull();
        expect($response->json('data.ledger_entry_id'))->toBeNull();
        expect(LedgerEntry::count())->toBe(0);
    });

    it('rejects a peso float, rather than rounding it out of sight', function (): void {
        ($this->file)(['amount_cents' => 450.75])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount_cents');
    });
});

describe('a driver on an expense', function (): void {
    beforeEach(function (): void {
        $this->driver = Driver::create([
            'name' => 'Marco Reyes',
            'licence_no' => 'N01-23-456789',
            'licence_expiry' => '2029-01-01',
        ]);
    });

    it('takes no driver either, because this is not where a crew is paid', function (): void {
        /**
         * The form asked who the money was for, and that sounded harmless.
         *
         * What it did was make this screen look like the place to file what a
         * crew cost — and what a crew costs is payroll and the daily sheet's
         * own driver and helper columns, both of which pay per period and per
         * run. A second, softer record of the same money, filed by hand
         * against a person, is how two answers to "what did we pay Marco" get
         * into one system.
         *
         * Other Expenses is the supplies and the sundries: the rice, the
         * tarpaulins, the tolls, the office rent. None of it belongs to a
         * person any more than it belongs to a truck.
         *
         * Ignored rather than refused, exactly as a truck is — the field is
         * gone from the form, and an old client that still sends one should
         * file its expense rather than fail on it.
         */
        $response = ($this->file)(['driver_id' => $this->driver->id])->assertCreated();

        expect($response->json('data.driver_id'))->toBeNull()
            ->and(Expense::firstOrFail()->driver_id)->toBeNull();
    });

    it('still prints the driver on a row filed before the form stopped asking', function (): void {
        /**
         * The column stays, and so does everything already in it.
         *
         * This is a form that stopped asking, not a fact that stopped
         * existing: a year of expenses filed against a driver must keep
         * reading back the way it was entered, or the change has quietly
         * rewritten history rather than changed a screen.
         */
        $expense = Expense::create([
            'category_id' => $this->food->id,
            'driver_id' => $this->driver->id,
            'date' => now()->toDateString(),
            'amount_cents' => 45_000,
            'currency' => 'PHP',
        ]);

        $response = $this->actingAs($this->accountant)
            ->getJson("/api/v1/expenses/{$expense->id}")
            ->assertOk();

        expect($response->json('data.driver_id'))->toBe($this->driver->id)
            ->and($response->json('data.driver_name'))->toBe('Marco Reyes');
    });

    it('leaves a filed driver alone when the row is corrected', function (): void {
        $expense = Expense::create([
            'category_id' => $this->food->id,
            'driver_id' => $this->driver->id,
            'date' => now()->toDateString(),
            'amount_cents' => 45_000,
            'currency' => 'PHP',
        ]);

        $response = $this->actingAs($this->accountant)
            ->patchJson("/api/v1/expenses/{$expense->id}", ['payee' => 'Aling Nena'])
            ->assertOk();

        expect($response->json('data.driver_id'))->toBe($this->driver->id);
    });
});

describe('the expense report', function (): void {
    beforeEach(function (): void {
        ($this->file)(['amount_cents' => 45_000]);
        ($this->file)(['amount_cents' => 30_000]);
        ($this->file)(['category_id' => $this->office->id, 'amount_cents' => 1_200_000]);
        // Cancelled: refused, and therefore not spend.
        ($this->file)(['amount_cents' => 99_000, 'status' => 'cancelled']);
    });

    it('totals by category, biggest first, and drops the empty ones', function (): void {
        $report = $this->actingAs($this->accountant)
            ->getJson('/api/v1/expenses/report')
            ->assertOk();

        $categories = $report->json('data.categories');

        expect($categories)->toHaveCount(2);
        expect($categories[0]['category']['key'])->toBe('office');
        expect($categories[0]['amount_cents'])->toBe(1_200_000);
        expect($categories[1]['amount_cents'])->toBe(75_000);
    });

    it('excludes cancelled claims from the total', function (): void {
        $report = $this->actingAs($this->accountant)->getJson('/api/v1/expenses/report');

        expect($report->json('data.total_cents'))->toBe(1_275_000);
    });

    it('charges all of it to the period, now that none of it names a unit', function (): void {
        $report = $this->actingAs($this->accountant)->getJson('/api/v1/expenses/report');

        // The split survives — `TruckRentService` still writes a vehicle onto
        // the monthly rent it raises, and rows filed before this keep the truck
        // they were filed with. What changed is that nothing a person can file
        // from the form lands on the attributed side any more.
        expect($report->json('data.overhead_cents'))->toBe(1_275_000);
        expect($report->json('data.attributed_cents'))->toBe(0);
    });
});

describe('the expense report over a chosen window', function (): void {
    beforeEach(function (): void {
        ($this->file)(['date' => '2026-06-05', 'amount_cents' => 10_000]);
        ($this->file)(['date' => '2026-06-30', 'amount_cents' => 20_000]);
        ($this->file)(['date' => '2026-07-01', 'amount_cents' => 40_000]);
    });

    it('totals only the spend inside the window, both ends included', function (): void {
        $report = $this->actingAs($this->accountant)
            ->getJson('/api/v1/expenses/report?from=2026-06-05&to=2026-06-30')
            ->assertOk();

        expect($report->json('data.total_cents'))->toBe(30_000);
        expect($report->json('data.entry_count'))->toBe(2);
        expect($report->json('data.range'))->toBe(['from' => '2026-06-05', 'to' => '2026-06-30']);
    });

    it('swaps ends given the wrong way round', function (): void {
        $report = $this->actingAs($this->accountant)
            ->getJson('/api/v1/expenses/report?from=2026-07-31&to=2026-06-01')
            ->assertOk();

        expect($report->json('data.total_cents'))->toBe(70_000);
        expect($report->json('data.range'))->toBe(['from' => '2026-06-01', 'to' => '2026-07-31']);
    });

    it('falls back to this month on an unreadable date rather than failing', function (): void {
        $report = $this->actingAs($this->accountant)
            ->getJson('/api/v1/expenses/report?from=not-a-date')
            ->assertOk();

        expect($report->json('data.range.from'))->toBe(now()->startOfMonth()->toDateString());
    });
});

describe('categories', function (): void {
    it('slugs a key from the name on create', function (): void {
        $response = $this->actingAs($this->accountant)
            ->postJson('/api/v1/expenses/categories', ['name' => 'Driver Bonus'])
            ->assertCreated();

        expect($response->json('data.key'))->toBe('driver-bonus');
    });

    it('keeps the key when the name is changed, so filed rows survive', function (): void {
        $id = $this->food->id;

        $response = $this->actingAs($this->accountant)
            ->patchJson("/api/v1/expenses/categories/$id", ['name' => 'Meals'])
            ->assertOk();

        expect($response->json('data.name'))->toBe('Meals');
        expect($response->json('data.key'))->toBe('food');
    });

    it('does not collide when a name is reused', function (): void {
        $this->actingAs($this->accountant)->postJson('/api/v1/expenses/categories', ['name' => 'Food']);

        $response = $this->actingAs($this->accountant)
            ->postJson('/api/v1/expenses/categories', ['name' => 'Food'])
            ->assertCreated();

        expect($response->json('data.key'))->toBe('food-3');
    });

    it('deletes a category nothing is filed against', function (): void {
        $id = $this->actingAs($this->accountant)
            ->postJson('/api/v1/expenses/categories', ['name' => 'Unused'])
            ->json('data.id');

        $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/expenses/categories/$id")
            ->assertNoContent();
    });

    it('retires a category that has spend, rather than taking the spend with it', function (): void {
        ($this->file)(['truck_id' => $this->truck->id]);

        $response = $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/expenses/categories/{$this->food->id}")
            ->assertOk();

        expect($response->json('data.status'))->toBe('inactive');
        expect($response->json('meta.retired'))->toBeTrue();
        expect(Expense::count())->toBe(1);
    });

    it('offers only the live categories when asked for active ones', function (): void {
        ($this->file)(['truck_id' => $this->truck->id]);
        $this->actingAs($this->accountant)->deleteJson("/api/v1/expenses/categories/{$this->food->id}");

        $active = $this->actingAs($this->accountant)
            ->getJson('/api/v1/expenses/categories?active=1')
            ->json('data');

        expect(collect($active)->pluck('key'))->not->toContain('food');
    });
});

describe('the effect on Profitability', function (): void {
    it('adds categorised lines to the truck total without touching the columns', function (): void {
        LedgerEntry::create([
            'truck_id' => $this->truck->id,
            'date' => now()->toDateString(),
            'trip_income_cents' => 1_000_000,
            'fuel_cents' => 300_000,
        ]);

        ($this->file)(['amount_cents' => 45_000]);

        $rollup = $this->actingAs($this->accountant)
            ->getJson('/api/v1/finance/profitability?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())
            ->assertOk();

        $row = collect($rollup->json('data.trucks'))->firstWhere('truck.id', $this->truck->id);

        /**
         * The unit's own columns are untouched, and the line is not on them.
         *
         * `other_expenses_cents` is zero for anything filed from the form now:
         * a meal belongs to the period rather than to the truck that happened
         * to be out that day. What reaches a unit is what the unit itself cost
         * — the five workbook columns, and a maintenance job's cost landing in
         * the Maintenance one.
         */
        expect($row['fuel_cents'])->toBe(300_000);
        expect($row['other_expenses_cents'])->toBe(0);
        expect($row['total_expenses_cents'])->toBe(300_000);
        expect($row['net_income_cents'])->toBe(700_000);

        // And it is still the period's cost, on the totals rather than lost.
        expect($rollup->json('data.totals.overhead_cents'))->toBe(45_000);
    });

    it('charges overhead to the period but to no truck', function (): void {
        LedgerEntry::create([
            'truck_id' => $this->truck->id,
            'date' => now()->toDateString(),
            'trip_income_cents' => 1_000_000,
        ]);

        ($this->file)(['category_id' => $this->office->id, 'amount_cents' => 200_000]);

        $rollup = $this->actingAs($this->accountant)
            ->getJson('/api/v1/finance/profitability?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())
            ->assertOk();

        $row = collect($rollup->json('data.trucks'))->firstWhere('truck.id', $this->truck->id);

        expect($row['total_expenses_cents'])->toBe(0);
        expect($rollup->json('data.totals.overhead_cents'))->toBe(200_000);
        expect($rollup->json('data.totals.total_expenses_cents'))->toBe(200_000);
        expect($rollup->json('data.totals.net_income_cents'))->toBe(800_000);
    });
});
