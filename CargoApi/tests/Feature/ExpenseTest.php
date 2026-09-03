<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
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
    it('records it against a category and a truck', function (): void {
        $response = ($this->file)(['truck_id' => $this->truck->id, 'payee' => 'Aling Nena'])
            ->assertCreated();

        expect($response->json('data.category_name'))->toBe('Food');
        expect($response->json('data.truck_label'))->toBe('Truck 1');
        expect($response->json('data.amount_cents'))->toBe(45_000);
        expect($response->json('data.status'))->toBe('active');
    });

    it('opens the day sheet for the truck if the day has none', function (): void {
        expect(LedgerEntry::count())->toBe(0);

        $response = ($this->file)(['truck_id' => $this->truck->id])->assertCreated();

        expect(LedgerEntry::count())->toBe(1);
        expect($response->json('data.ledger_entry_id'))->toBe(LedgerEntry::first()->id);
    });

    it('joins the sheet that already exists rather than opening a second', function (): void {
        $existing = LedgerEntry::create([
            'truck_id' => $this->truck->id,
            'date' => now()->toDateString(),
            'fuel_cents' => 300_000,
        ]);

        $response = ($this->file)(['truck_id' => $this->truck->id])->assertCreated();

        expect(LedgerEntry::count())->toBe(1);
        expect($response->json('data.ledger_entry_id'))->toBe($existing->id);
        // The five columns are the office's. Filing a line must not touch them.
        expect($existing->refresh()->fuel_cents)->toBe(300_000);
    });

    it('leaves overhead unattached, because it belongs to no unit', function (): void {
        $response = ($this->file)(['category_id' => $this->office->id, 'amount_cents' => 1_200_000])
            ->assertCreated();

        expect($response->json('data.truck_id'))->toBeNull();
        expect($response->json('data.ledger_entry_id'))->toBeNull();
        expect(LedgerEntry::count())->toBe(0);
    });

    it('follows the expense to a new sheet when the date moves', function (): void {
        $id = ($this->file)(['truck_id' => $this->truck->id])->json('data.id');
        $first = Expense::findOrFail($id)->ledger_entry_id;

        $response = $this->actingAs($this->accountant)
            ->patchJson("/api/v1/expenses/$id", ['date' => now()->subDays(3)->toDateString()])
            ->assertOk();

        expect($response->json('data.ledger_entry_id'))->not->toBe($first);
        expect(LedgerEntry::count())->toBe(2);
    });

    it('rejects a peso float, rather than rounding it out of sight', function (): void {
        ($this->file)(['amount_cents' => 450.75])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount_cents');
    });
});

describe('the expense report', function (): void {
    beforeEach(function (): void {
        ($this->file)(['truck_id' => $this->truck->id, 'amount_cents' => 45_000]);
        ($this->file)(['truck_id' => $this->truck->id, 'amount_cents' => 30_000]);
        ($this->file)(['category_id' => $this->office->id, 'amount_cents' => 1_200_000]);
        // Cancelled: refused, and therefore not spend.
        ($this->file)(['truck_id' => $this->truck->id, 'amount_cents' => 99_000, 'status' => 'cancelled']);
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

    it('separates overhead from what a truck can be charged for', function (): void {
        $report = $this->actingAs($this->accountant)->getJson('/api/v1/expenses/report');

        expect($report->json('data.overhead_cents'))->toBe(1_200_000);
        expect($report->json('data.attributed_cents'))->toBe(75_000);
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

        ($this->file)(['truck_id' => $this->truck->id, 'amount_cents' => 45_000]);

        $rollup = $this->actingAs($this->accountant)
            ->getJson('/api/v1/finance/profitability?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())
            ->assertOk();

        $row = collect($rollup->json('data.trucks'))->firstWhere('truck.id', $this->truck->id);

        expect($row['fuel_cents'])->toBe(300_000);
        expect($row['other_expenses_cents'])->toBe(45_000);
        expect($row['total_expenses_cents'])->toBe(345_000);
        expect($row['net_income_cents'])->toBe(655_000);
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
