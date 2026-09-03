<?php

declare(strict_types=1);

use App\Domain\Finance\Services\FinanceService;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\Demo\LedgerSeeder;
use Illuminate\Support\Carbon;

/**
 * These lock the transcribed ledger to the figures the source workbook prints
 * ("v3 Cargorush Master Dashboard 2026.xlsx").
 *
 * If someone re-keys a row in `LedgerSeeder`, the aggregate it feeds fails
 * here rather than quietly showing a wrong dashboard.
 */
beforeEach(function (): void {
    $this->seed(FleetSeeder::class);
    $this->seed(LedgerSeeder::class);

    $this->finance = app(FinanceService::class);
});

/** Compare in pesos — the unit the workbook shows — to two decimal places. */
function pesos(int $cents): float
{
    return round($cents / 100, 2);
}

/**
 * @param  array<int, array<string, mixed>>  $rows
 * @return array<string, mixed>
 */
function byPlate(array $rows, string $plate): array
{
    foreach ($rows as $row) {
        if ($row['truck']['plate'] === $plate) {
            return $row;
        }
    }

    throw new RuntimeException("no row for {$plate}");
}

describe('DashBoard sheet — 10-day window, 5 to 15 Apr 2026', function (): void {
    beforeEach(function (): void {
        $this->rows = $this->finance->pnlByTruck(
            Carbon::parse('2026-04-05'),
            Carbon::parse('2026-04-15'),
        );
    });

    it('matches MAR1390', function (): void {
        $r = byPlate($this->rows, 'MAR1390');

        expect(pesos($r['trip_income_cents']))->toBe(92982.68)
            ->and(pesos($r['driver_salary_cents']))->toBe(6000.0)
            ->and(pesos($r['helper_salary_cents']))->toBe(8000.0)
            ->and(pesos($r['allowance_cents']))->toBe(4800.0)
            ->and(pesos($r['total_expenses_cents']))->toBe(18800.0)
            ->and(pesos($r['net_income_cents']))->toBe(74182.68);
    });

    it('matches CBS8862', function (): void {
        $r = byPlate($this->rows, 'CBS8862');

        expect(pesos($r['trip_income_cents']))->toBe(147314.07)
            ->and(pesos($r['total_expenses_cents']))->toBe(29600.0)
            ->and(pesos($r['net_income_cents']))->toBe(117714.07);
    });

    it('matches CCE7342, which runs at a loss', function (): void {
        $r = byPlate($this->rows, 'CCE7342');

        expect(pesos($r['total_expenses_cents']))->toBe(3800.0)
            ->and(pesos($r['net_income_cents']))->toBe(-3800.0);
    });

    it('matches the TOTAL row', function (): void {
        $t = $this->finance->periodTotals($this->rows);

        expect(pesos($t['trip_income_cents']))->toBe(240296.75)
            ->and(pesos($t['driver_salary_cents']))->toBe(16000.0)
            ->and(pesos($t['helper_salary_cents']))->toBe(21600.0)
            ->and(pesos($t['allowance_cents']))->toBe(14600.0)
            ->and(pesos($t['total_expenses_cents']))->toBe(52200.0)
            ->and(pesos($t['net_income_cents']))->toBe(188096.75);
    });

    it('names CBS8862 the best performing truck', function (): void {
        expect($this->finance->bestPerformer($this->rows)['truck']['plate'])->toBe('CBS8862');
    });

    it('averages profit over the units that traded, not the idle ones', function (): void {
        // Five units carry rows in this window, but only three moved money.
        $avg = $this->finance->averageProfitPerTruck($this->rows);

        expect($avg['trucks'])->toBe(3)
            ->and(pesos($avg['cents']))->toBe(62698.92);
    });

    it('matches the "% OF NET INCOME" column', function (): void {
        $share = fn (string $plate): float => round(byPlate($this->rows, $plate)['net_share'], 6);

        expect($share('MAR1390'))->toBe(0.394386)
            ->and($share('CBS8862'))->toBe(0.625817)
            ->and($share('CCE7342'))->toBe(-0.020202);
    });

    it('keeps every unit, including the two with no plate', function (): void {
        expect($this->rows)->toHaveCount(8);

        $unassigned = array_filter($this->rows, fn (array $r): bool => $r['truck']['plate'] === null);

        expect($unassigned)->toHaveCount(2);
    });
});

describe('Summary sheet — Q1 2026', function (): void {
    beforeEach(function (): void {
        $this->quarters = $this->finance->quarters(2026);
        $q1 = $this->quarters[0];

        $this->rows = $this->finance->pnlByTruck(
            Carbon::parse($q1['from']),
            Carbon::parse($q1['to']),
        );
    });

    it('uses the workbook quarter boundaries', function (): void {
        expect($this->quarters[0])->toMatchArray([
            'key' => 'q1',
            'from' => '2026-01-01',
            'to' => '2026-03-31',
        ]);
    });

    it('matches MAR1390', function (): void {
        $r = byPlate($this->rows, 'MAR1390');

        expect(pesos($r['trip_income_cents']))->toBe(92982.68)
            ->and(pesos($r['driver_salary_cents']))->toBe(7500.0)
            ->and(pesos($r['helper_salary_cents']))->toBe(10000.0)
            ->and(pesos($r['allowance_cents']))->toBe(4700.0)
            ->and(pesos($r['total_expenses_cents']))->toBe(22200.0)
            ->and(pesos($r['net_income_cents']))->toBe(70782.68);
    });

    it('matches CBS8862 including fuel and maintenance', function (): void {
        $r = byPlate($this->rows, 'CBS8862');

        expect(pesos($r['trip_income_cents']))->toBe(90139.0)
            ->and(pesos($r['fuel_cents']))->toBe(18542.96)
            ->and(pesos($r['maintenance_cents']))->toBe(1000.0)
            ->and(pesos($r['total_expenses_cents']))->toBe(41142.96)
            ->and(pesos($r['net_income_cents']))->toBe(48996.04);
    });

    it('matches the two loss-making trucks', function (): void {
        expect(pesos(byPlate($this->rows, 'LAQ8325')['net_income_cents']))->toBe(-12032.35)
            ->and(pesos(byPlate($this->rows, 'CCE7342')['net_income_cents']))->toBe(-7190.92);
    });

    it('matches CCP9548', function (): void {
        $r = byPlate($this->rows, 'CCP9548');

        expect(pesos($r['trip_income_cents']))->toBe(102989.0)
            ->and(pesos($r['total_expenses_cents']))->toBe(29084.43)
            ->and(pesos($r['net_income_cents']))->toBe(73904.57);
    });
});

describe('per-truck sheet totals', function (): void {
    it('matches each sheet header', function (string $plate, float $income, float $expenses, float $net): void {
        $rows = $this->finance->pnlByTruck(
            Carbon::parse('2000-01-01'),
            Carbon::parse('2100-01-01'),
        );

        $r = byPlate($rows, $plate);

        expect(pesos($r['trip_income_cents']))->toBe($income)
            ->and(pesos($r['total_expenses_cents']))->toBe($expenses)
            ->and(pesos($r['net_income_cents']))->toBe($net);
    })->with([
        ['MAR1390', 185965.36, 41000.0, 144965.36],
        ['CBS8862', 247807.07, 74142.96, 173664.11],
        ['CCE7342', 20708.0, 35498.92, -14790.92],
        ['CCP9548', 102989.0, 29084.43, 73904.57],
    ]);
});

describe('a period with nothing in it', function (): void {
    it('gives no best performer when nobody is in profit', function (): void {
        $rows = $this->finance->pnlByTruck(
            Carbon::parse('2026-12-01'),
            Carbon::parse('2026-12-31'),
        );

        expect($this->finance->bestPerformer($rows))->toBeNull();
    });

    it('reports no margin rather than a zero one', function (): void {
        $rows = $this->finance->pnlByTruck(
            Carbon::parse('2026-12-01'),
            Carbon::parse('2026-12-31'),
        );

        expect($this->finance->periodTotals($rows)['margin'])->toBeNull();
    });
});
