<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Shared\Enums\DeductionSchedule;

/**
 * What the government takes out of a payslip.
 *
 * SSS, PhilHealth, Pag-IBIG and withholding tax, worked out from the rates in
 * `config/cargo.php` — where they live precisely because **they change, and
 * they change by circular rather than by law.** All four have moved in the last
 * few years. A payroll module with the rates in code is wrong within a year and
 * needs a deployment to fix.
 *
 * ## What this is honest about
 *
 * The real SSS schedule is a bracketed table of monthly salary credits, not a
 * percentage. This is the percentage-with-a-ceiling approximation that every
 * small office starts with: close in the middle of the range, wrong at the ends.
 * The same is true, less severely, of PhilHealth's floor and ceiling. Every
 * figure it produces is editable on the pay run line, which is the escape hatch
 * that makes an approximation usable — an office that knows the right number
 * types the right number.
 *
 * Withholding tax is the one that is *not* approximated: it is the BIR's own
 * graduated table, applied to taxable pay in the order the BIR applies it —
 * gross **less the three contributions**, because taxing before them overstates
 * the tax on every payslip.
 *
 * ## Monthly rates on a semi-monthly payslip
 *
 * The contributions are monthly figures. A fleet paying twice a month splits
 * each in half, which is what offices do and what `runs_per_month` says. The
 * withholding table is the semi-monthly one, so it is applied to the period's
 * own taxable pay rather than halved — that is the difference between a
 * contribution and a tax bracket, and treating them alike is the usual mistake.
 */
class StatutoryDeductions
{
    /**
     * The four deductions on one payslip.
     *
     * The three contributions are monthly figures, and `$schedule` decides how
     * much of each this cutoff carries — half, all, or none. That is the firm's
     * own policy rather than a rate; see `DeductionSchedule`.
     *
     * @param  int  $monthlyBasicCents  The person's monthly basic — the figure the
     *                                  contributions are computed from, whatever
     *                                  the period being paid.
     * @param  int  $periodGrossCents  What this run actually pays them, which is
     *                                 what the tax table reads.
     * @param  int  $index  Which run of the month this is, from zero.
     * @param  bool  $isOnlyRun  True where payroll runs once a month, so there
     *                           is no second payslip to spread anything onto.
     * @param  array{sss?: bool, philhealth?: bool, pagibig?: bool}  $enrolled  Which
     *                                                                          agencies this person is registered with. Absent is enrolled.
     * @return array{sss: int, philhealth: int, pagibig: int, withholding_tax: int}
     */
    public function for(
        int $monthlyBasicCents,
        int $periodGrossCents,
        int $index = 0,
        int $count = 1,
        ?DeductionSchedule $schedule = null,
        array $enrolled = [],
    ): array {
        $schedule ??= DeductionSchedule::Split;

        /**
         * A contribution the person is not enrolled for is nothing at all.
         *
         * Not "zero after the arithmetic" but skipped before it, which matters
         * for PhilHealth: its floor means somebody on a low basic contributes
         * on ₱10,000 they do not earn, and running that and then discarding it
         * would be a figure in the logs nobody could account for.
         *
         * Absent means enrolled. Every employee on a roster predating this had
         * all three, and a default of "not enrolled" would silently stop the
         * contributions of an entire company the day it deployed.
         */
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

        /**
         * The BIR's order: contributions first, then tax on what is left.
         *
         * The contributions subtracted are the ones actually taken on *this*
         * cutoff, so a firm that loads them onto one payslip moves the tax with
         * them — which is right, because the tax follows the money withheld.
         *
         * The same sentence is why an unenrolled person is taxed slightly more,
         * and why that is correct rather than a penalty: the deduction from
         * taxable pay exists because the contribution was withheld, and nothing
         * was withheld.
         */
        $taxable = max(0, $periodGrossCents - $sss - $philhealth - $pagibig);

        return [
            'sss' => $sss,
            'philhealth' => $philhealth,
            'pagibig' => $pagibig,
            'withholding_tax' => $this->withholding($taxable, $monthlyBasicCents),
        ];
    }

    /**
     * Is this salary inside the range that pays no income tax at all?
     *
     * Read off the **monthly basic** rather than off one period's gross, and
     * that is the substance of the rule rather than a detail. A person on
     * ₱20,000 a month earns under the annual exemption and owes no income tax —
     * so a ₱3,000 allowance in one fortnight must not tip them into a tax
     * bracket for that fortnight and out again the next. Their *salary* decides
     * whether they are a taxpayer; the table only decides how much.
     *
     * Public because the payslip says so on screen: a ₱0.00 tax line that does
     * not explain itself is the line an employee asks about.
     */
    public function isExempt(int $monthlyBasicCents): bool
    {
        $threshold = (int) config('cargo.payroll.withholding.exempt_monthly_at_or_below_cents', 0);

        return $threshold > 0 && $monthlyBasicCents <= $threshold;
    }

    /** The employee's share, up to the salary credit ceiling. */
    private function sss(int $monthlyBasicCents): int
    {
        $rate = (int) config('cargo.payroll.sss.employee_rate_bp', 450);
        $ceiling = (int) config('cargo.payroll.sss.ceiling_cents', 3_500_000);

        return $this->percentOf(min($monthlyBasicCents, $ceiling), $rate);
    }

    /** 2.5% of the basic, held between a floor and a ceiling. */
    private function philhealth(int $monthlyBasicCents): int
    {
        $rate = (int) config('cargo.payroll.philhealth.employee_rate_bp', 250);
        $floor = (int) config('cargo.payroll.philhealth.floor_cents', 1_000_000);
        $ceiling = (int) config('cargo.payroll.philhealth.ceiling_cents', 10_000_000);

        $basis = max($floor, min($monthlyBasicCents, $ceiling));

        // Nothing to contribute on nothing: an employee with no basic on record
        // — somebody paid per trip — should not be charged the floor.
        if ($monthlyBasicCents <= 0) {
            return 0;
        }

        return $this->percentOf($basis, $rate);
    }

    /** 2% of the basic, capped — and the cap binds at a low salary. */
    private function pagibig(int $monthlyBasicCents): int
    {
        $rate = (int) config('cargo.payroll.pagibig.employee_rate_bp', 200);
        $cap = (int) config('cargo.payroll.pagibig.cap_cents', 20_000);

        return min($cap, $this->percentOf($monthlyBasicCents, $rate));
    }

    /**
     * The BIR's graduated table, on taxable pay for this period.
     *
     * Nothing at all below the exemption — see `isExempt()`. Above it, the
     * brackets are read from the bottom up so the *highest* one that applies
     * wins; reading downwards and stopping at the first match is the classic
     * off-by-one that taxes a manager at 15%.
     */
    private function withholding(int $taxableCents, int $monthlyBasicCents): int
    {
        // The salary range that triggers income tax at all. Checked before the
        // table rather than left to the table's own zero-rated first bracket,
        // because the two ask different questions: the bracket looks at one
        // fortnight's taxable pay, and this looks at what the person earns.
        if ($this->isExempt($monthlyBasicCents)) {
            return 0;
        }

        if ($taxableCents <= 0) {
            return 0;
        }

        /** @var array<int, array{over: int, base: int, rate_bp: int}> $brackets */
        $brackets = (array) config('cargo.payroll.withholding.brackets', []);

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

    /**
     * Basis points of an amount, rounded to the centavo.
     *
     * `intdiv` on the way out, so nothing here produces a fraction of a
     * centavo that a payslip would then have to hide.
     */
    private function percentOf(int $cents, int $basisPoints): int
    {
        return (int) round(($cents * $basisPoints) / 10_000);
    }
}
