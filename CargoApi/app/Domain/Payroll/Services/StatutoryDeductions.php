<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Shared\Enums\DeductionSchedule;

class StatutoryDeductions
{
    /**
     * @param  bool  $isOnlyRun
     * @param  array{sss?: bool, philhealth?: bool, pagibig?: bool}  $enrolled
     * @return array{sss: int, philhealth: int, pagibig: int, withholding_tax: int}
     */
    public function for(
        int $monthlyBasicCents,
        int $periodGrossCents,
        int $index = 0,
        int $count = 1,
        ?DeductionSchedule $schedule = null,
        array $enrolled = [],
        int $monthlyTaxableExtrasCents = 0,
    ): array {
        $schedule ??= DeductionSchedule::Split;

        $takes = static fn (string $agency): bool => ($enrolled[$agency] ?? true) === true;

        $sss = $takes('sss')
            ? $schedule->shareOf($this->sss($monthlyBasicCents), $index, $count)
            : 0;
        $philhealth = $takes('philhealth')
            ? $schedule->shareOf($this->philhealth($monthlyBasicCents), $index, $count)
            : 0;
        $pagibig = $takes('pagibig')
            ? $schedule->shareOf($this->pagibig($monthlyBasicCents), $index, $count)
            : 0;

        $taxable = max(0, $periodGrossCents - $sss - $philhealth - $pagibig);

        return [
            'sss' => $sss,
            'philhealth' => $philhealth,
            'pagibig' => $pagibig,
            'withholding_tax' => $this->isExempt($monthlyBasicCents, $monthlyTaxableExtrasCents)
                ? 0
                : $this->withholding($taxable, $count),
        ];
    }

    /**
     * @param  array{sss?: bool, philhealth?: bool, pagibig?: bool}  $enrolled
     * @return array{sss: int, ec: int, philhealth: int, pagibig: int}
     */
    public function employerFor(
        int $monthlyBasicCents,
        int $index = 0,
        int $count = 1,
        ?DeductionSchedule $schedule = null,
        array $enrolled = [],
    ): array {
        $schedule ??= DeductionSchedule::Split;
        $takes = static fn (string $agency): bool => ($enrolled[$agency] ?? true) === true;
        $share = static fn (int $monthly): int => $schedule->shareOf($monthly, $index, $count);

        if ($monthlyBasicCents <= 0) {
            return ['sss' => 0, 'ec' => 0, 'philhealth' => 0, 'pagibig' => 0];
        }

        $credit = $this->salaryCredit($monthlyBasicCents);

        $ec = $credit < (int) config('cargo.payroll.sss.ec_threshold_cents', 1_500_000)
            ? (int) config('cargo.payroll.sss.ec_low_cents', 1_000)
            : (int) config('cargo.payroll.sss.ec_high_cents', 3_000);

        return [
            'sss' => $takes('sss')
                ? $share($this->percentOf($credit, (int) config('cargo.payroll.sss.employer_rate_bp', 1000)))
                : 0,
            'ec' => $takes('sss') ? $share($ec) : 0,
            'philhealth' => $takes('philhealth')
                ? $share($this->percentOf(
                    $this->philhealthBasis($monthlyBasicCents),
                    (int) config('cargo.payroll.philhealth.employer_rate_bp', 250),
                ))
                : 0,
            'pagibig' => $takes('pagibig')
                ? $share(min(
                    (int) config('cargo.payroll.pagibig.employer_cap_cents', 20_000),
                    $this->percentOf($monthlyBasicCents, (int) config('cargo.payroll.pagibig.employer_rate_bp', 200)),
                ))
                : 0,
        ];
    }

    public function isExempt(int $monthlyBasicCents, int $monthlyTaxableExtrasCents = 0): bool
    {
        $threshold = (int) config('cargo.payroll.withholding.exempt_monthly_at_or_below_cents', 0);

        return $threshold > 0 && $monthlyBasicCents + max(0, $monthlyTaxableExtrasCents) <= $threshold;
    }

    private function sss(int $monthlyBasicCents): int
    {
        if ($monthlyBasicCents <= 0) {
            return 0;
        }

        $rate = (int) config('cargo.payroll.sss.employee_rate_bp', 500);

        return $this->percentOf($this->salaryCredit($monthlyBasicCents), $rate);
    }

    private function salaryCredit(int $monthlyBasicCents): int
    {
        $floor = (int) config('cargo.payroll.sss.floor_cents', 500_000);
        $ceiling = (int) config('cargo.payroll.sss.ceiling_cents', 3_500_000);

        return max($floor, min($monthlyBasicCents, $ceiling));
    }

    private function philhealth(int $monthlyBasicCents): int
    {
        $rate = (int) config('cargo.payroll.philhealth.employee_rate_bp', 250);

        if ($monthlyBasicCents <= 0) {
            return 0;
        }

        return $this->percentOf($this->philhealthBasis($monthlyBasicCents), $rate);
    }

    private function philhealthBasis(int $monthlyBasicCents): int
    {
        $floor = (int) config('cargo.payroll.philhealth.floor_cents', 1_000_000);
        $ceiling = (int) config('cargo.payroll.philhealth.ceiling_cents', 10_000_000);

        return max($floor, min($monthlyBasicCents, $ceiling));
    }

    private function pagibig(int $monthlyBasicCents): int
    {
        $rate = (int) config('cargo.payroll.pagibig.employee_rate_bp', 200);
        $cap = (int) config('cargo.payroll.pagibig.cap_cents', 20_000);

        return min($cap, $this->percentOf($monthlyBasicCents, $rate));
    }

    private function withholding(int $taxableCents, int $count = 2): int
    {
        if ($taxableCents <= 0) {
            return 0;
        }

        if ($count === 2) {
            return $this->onTable($taxableCents, $this->table('semi_monthly'));
        }

        if ($count <= 1) {
            return $this->onTable($taxableCents, $this->table('monthly'));
        }

        return (int) round($this->onTable($taxableCents * $count, $this->table('monthly')) / $count);
    }

    /** @return array<int, array{over: int, base: int, rate_bp: int}> */
    private function table(string $frequency): array
    {
        $brackets = (array) config('cargo.payroll.withholding.brackets', []);

        if (array_is_list($brackets)) {
            return $brackets;
        }

        return (array) ($brackets[$frequency] ?? []);
    }

    /** @param array<int, array{over: int, base: int, rate_bp: int}> $brackets */
    private function onTable(int $taxableCents, array $brackets): int
    {
        $applicable = null;

        foreach ($brackets as $bracket) {
            if ($taxableCents > (int) $bracket['over'] || (int) $bracket['over'] === 0) {
                $applicable = $bracket;
            }
        }

        if ($applicable === null) {
            return 0;
        }

        $excess = $taxableCents - (int) $applicable['over'];

        return (int) $applicable['base'] + $this->percentOf(max(0, $excess), (int) $applicable['rate_bp']);
    }

    private function percentOf(int $cents, int $basisPoints): int
    {
        return (int) round(($cents * $basisPoints) / 10_000);
    }
}
