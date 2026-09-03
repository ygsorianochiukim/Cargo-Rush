<?php

declare(strict_types=1);

use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\NavigationSeeder;

/**
 * Sales by day, week and month.
 *
 * One roll-up over three bucket sizes, read off the ledger so the figure agrees
 * with Profitability and the Quarterly Summary. The tests that matter are the
 * bucketing ones: a week that spans a month boundary, and a day of spend with
 * no takings on it — which is where a naive join drops the expense.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(ExpenseCategorySeeder::class);

    $this->accountant = User::factory()->create(['role' => Role::Accountant]);
    $this->truck = Truck::create(['label' => 'Truck 1', 'plate' => 'NCR 4412', 'position' => 1]);

    $this->day = function (string $date, int $income, int $fuel = 0): LedgerEntry {
        return LedgerEntry::create([
            'truck_id' => $this->truck->id,
            'date' => $date,
            'trip_income_cents' => $income,
            'fuel_cents' => $fuel,
        ]);
    };

    $this->sales = fn (array $query = []) => $this->actingAs($this->accountant)
        ->getJson('/api/v1/finance/sales?'.http_build_query($query));
});

describe('daily', function (): void {
    it('gives one bucket per day that traded', function (): void {
        ($this->day)('2026-08-20', 500_000);
        ($this->day)('2026-08-21', 300_000);

        $response = ($this->sales)([
            'granularity' => 'daily',
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ])->assertOk();

        expect($response->json('data.series'))->toHaveCount(2);
        expect($response->json('data.series.0.label'))->toBe('20 Aug 2026');
        expect($response->json('data.totals.sales_cents'))->toBe(800_000);
    });

    it('nets the day sheet expenses off the takings', function (): void {
        ($this->day)('2026-08-20', 500_000, fuel: 120_000);

        $response = ($this->sales)(['from' => '2026-08-01', 'to' => '2026-08-31'])->assertOk();

        expect($response->json('data.series.0.expenses_cents'))->toBe(120_000);
        expect($response->json('data.series.0.net_cents'))->toBe(380_000);
    });

    it('counts categorised spend on a day with no takings at all', function (): void {
        ($this->day)('2026-08-20', 500_000);

        $this->actingAs($this->accountant)->postJson('/api/v1/expenses', [
            'category_id' => ExpenseCategory::where('key', 'office')->firstOrFail()->id,
            'date' => '2026-08-23',
            'amount_cents' => 200_000,
        ])->assertCreated();

        $response = ($this->sales)(['from' => '2026-08-01', 'to' => '2026-08-31'])->assertOk();

        // Two buckets: the day that earned, and the day that only spent. The
        // second one is the whole reason expenses are bucketed separately.
        expect($response->json('data.series'))->toHaveCount(2);
        expect($response->json('data.totals.expenses_cents'))->toBe(200_000);
        expect($response->json('data.totals.net_cents'))->toBe(300_000);
    });

    it('sorts the series oldest first', function (): void {
        ($this->day)('2026-08-25', 100_000);
        ($this->day)('2026-08-03', 200_000);

        $keys = collect(($this->sales)(['from' => '2026-08-01', 'to' => '2026-08-31'])->json('data.series'))
            ->pluck('key');

        expect($keys->all())->toBe(['2026-08-03', '2026-08-25']);
    });
});

describe('weekly', function (): void {
    it('collapses days in the same week into one bucket', function (): void {
        // Monday, Wednesday, Saturday of the same week.
        ($this->day)('2026-08-17', 100_000);
        ($this->day)('2026-08-19', 200_000);
        ($this->day)('2026-08-22', 300_000);

        $response = ($this->sales)([
            'granularity' => 'weekly',
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ])->assertOk();

        expect($response->json('data.series'))->toHaveCount(1);
        expect($response->json('data.series.0.sales_cents'))->toBe(600_000);
        expect($response->json('data.series.0.from'))->toBe('2026-08-17');
    });

    it('keeps a week whole across a month boundary', function (): void {
        // 31 August 2026 is a Monday; 2 September is the Wednesday after it.
        ($this->day)('2026-08-31', 100_000);
        ($this->day)('2026-09-02', 200_000);

        $response = ($this->sales)([
            'granularity' => 'weekly',
            'from' => '2026-08-01',
            'to' => '2026-09-30',
        ])->assertOk();

        expect($response->json('data.series'))->toHaveCount(1);
        expect($response->json('data.series.0.sales_cents'))->toBe(300_000);
    });
});

describe('monthly', function (): void {
    it('gives one bucket per month, labelled for a human', function (): void {
        ($this->day)('2026-07-05', 100_000);
        ($this->day)('2026-07-28', 150_000);
        ($this->day)('2026-08-02', 400_000);

        $response = ($this->sales)([
            'granularity' => 'monthly',
            'from' => '2026-07-01',
            'to' => '2026-08-31',
        ])->assertOk();

        expect($response->json('data.series'))->toHaveCount(2);
        expect($response->json('data.series.0.label'))->toBe('July 2026');
        expect($response->json('data.series.0.sales_cents'))->toBe(250_000);
        expect($response->json('data.series.1.sales_cents'))->toBe(400_000);
    });
});

describe('the totals tile', function (): void {
    it('averages over the buckets that traded, not over the calendar', function (): void {
        ($this->day)('2026-08-20', 500_000);
        ($this->day)('2026-08-21', 300_000);

        $response = ($this->sales)(['from' => '2026-08-01', 'to' => '2026-08-31'])->assertOk();

        // 800,000 over two trading days, not over the 31 in the window.
        expect($response->json('data.totals.average_cents'))->toBe(400_000);
    });

    it('names the best bucket', function (): void {
        ($this->day)('2026-08-20', 500_000);
        ($this->day)('2026-08-21', 300_000);

        $best = ($this->sales)(['from' => '2026-08-01', 'to' => '2026-08-31'])->json('data.totals.best');

        expect($best['sales_cents'])->toBe(500_000);
        expect($best['key'])->toBe('2026-08-20');
    });

    it('has no best bucket when nothing was earned', function (): void {
        ($this->day)('2026-08-20', 0, fuel: 90_000);

        $totals = ($this->sales)(['from' => '2026-08-01', 'to' => '2026-08-31'])->json('data.totals');

        expect($totals['best'])->toBeNull();
        expect($totals['margin'])->toBeNull();
        expect($totals['net_cents'])->toBe(-90_000);
    });

    it('returns an empty series rather than failing on a quiet window', function (): void {
        $response = ($this->sales)(['from' => '2026-01-01', 'to' => '2026-01-31'])->assertOk();

        expect($response->json('data.series'))->toBe([]);
        expect($response->json('data.totals.sales_cents'))->toBe(0);
        expect($response->json('data.totals.average_cents'))->toBe(0);
    });

    it('falls back to daily when asked for a granularity it does not have', function (): void {
        ($this->day)(now()->toDateString(), 100_000);

        expect(($this->sales)(['granularity' => 'hourly'])->json('data.granularity'))->toBe('daily');
    });
});
