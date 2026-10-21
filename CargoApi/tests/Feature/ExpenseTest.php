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

    it('files a utility bill against its biller, booked to rent and utilities', function (): void {
        $electricity = ExpenseCategory::where('key', 'electricity')->firstOrFail();
        $biller = Supplier::factory()->create(['name' => 'Davao Light & Power']);

        $response = ($this->file)([
            'category_id' => $electricity->id,
            'supplier_id' => $biller->id,
            'amount_cents' => 1_845_000,
            'pay_schedule' => 'monthly',
        ])->assertCreated();

        expect($response->json('data.category_name'))->toBe('Electricity')
            ->and($response->json('data.supplier_name'))->toBe('Davao Light & Power');

        foreach (['electricity', 'water', 'internet-phone', 'rent'] as $key) {
            expect(ExpenseCategory::where('key', $key)->firstOrFail()->accountCode())->toBe('5210');
        }
    });

    it('takes no truck, because what a unit costs is not filed here', function (): void {
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
        $response = ($this->file)(['driver_id' => $this->driver->id])->assertCreated();

        expect($response->json('data.driver_id'))->toBeNull()
            ->and(Expense::firstOrFail()->driver_id)->toBeNull();
    });

    it('still prints the driver on a row filed before the form stopped asking', function (): void {
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

        expect($row['fuel_cents'])->toBe(300_000);
        expect($row['other_expenses_cents'])->toBe(0);
        expect($row['total_expenses_cents'])->toBe(300_000);
        expect($row['net_income_cents'])->toBe(700_000);

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
