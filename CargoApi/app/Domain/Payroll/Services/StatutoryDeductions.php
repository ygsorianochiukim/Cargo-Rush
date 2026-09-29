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
 * withholding table is the one for the run's **own frequency**, applied to the
 * period's own taxable pay rather than halved — that is the difference between
 * a contribution and a tax bracket, and treating them alike is the usual
 * mistake. See `withholding()` for which table a run reads.
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
     * @param  int  $monthlyTaxableExtrasCents  Recurring taxable earnings, as a
     *                                          month — read by the exemption
     *                                          test alongside the basic.
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
            // Exemption first — the salary decides whether the person is a
            // taxpayer at all, and the table only how much. See `isExempt()`.
            'withholding_tax' => $this->isExempt($monthlyBasicCents, $monthlyTaxableExtrasCents)
                ? 0
                : $this->withholding($taxable, $count),
        ];
    }

    /**
     * What the **firm** pays the agencies on top of this payslip.
     *
     * Nothing here comes off the person's pay — it is a cost of employing them,
     * and a debt to the agencies until remitted. It used to be computed
     * nowhere, so every report understated what a person costs by roughly an
     * eighth of their salary.
     *
     * Read off the same monthly basic, the same enrolment and the same deduction
     * schedule as the employee's side, deliberately: the agencies remit both
     * halves on one form for one month, and a firm that loads the employee's
     * share onto the second cutoff has its own share on that cutoff too. A
     * person not enrolled with an agency costs the firm nothing there either.
     *
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

        // Nothing on nothing — the same rule as the employee's side, and for
        // the same per-trip driver with an empty fortnight.
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
            // EC rides on SSS membership: it is remitted on the same R-5.
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
     * ## Recurring taxable allowances count
     *
     * The salary is the basic **plus every recurring taxable earning**. A
     * taxable allowance in the salary structure is paid every month and is
     * compensation to the BIR exactly as the basic is. This used to read the
     * basic alone, so a person on ₱18,000 with a ₱6,000 taxable allowance —
     * ₱288,000 a year, over the ₱250,000 exemption — was withheld nothing all
     * year and owed all of it at the annual adjustment.
     *
     * A one-off typed onto a payslip (an adjustment, overtime) is still not
     * counted, for the reason above: it is not what the person earns.
     *
     * Public because the payslip says so on screen: a ₱0.00 tax line that does
     * not explain itself is the line an employee asks about.
     */
    public function isExempt(int $monthlyBasicCents, int $monthlyTaxableExtrasCents = 0): bool
    {
        $threshold = (int) config('cargo.payroll.withholding.exempt_monthly_at_or_below_cents', 0);

        return $threshold > 0 && $monthlyBasicCents + max(0, $monthlyTaxableExtrasCents) <= $threshold;
    }

    /**
     * The employee's share, on a salary credit held between the floor and the
     * ceiling — ₱250 to ₱1,750 a month on the 2025 schedule.
     *
     * Nothing on nothing, as with PhilHealth: a per-trip driver with an empty
     * period is not charged the floor on money they did not earn.
     */
    private function sss(int $monthlyBasicCents): int
    {
        if ($monthlyBasicCents <= 0) {
            return 0;
        }

        $rate = (int) config('cargo.payroll.sss.employee_rate_bp', 500);

        return $this->percentOf($this->salaryCredit($monthlyBasicCents), $rate);
    }

    /**
     * The monthly salary credit both SSS shares are computed on — the basic,
     * held between the floor and the ceiling. One place, so the employer's 10%
     * and the employee's 5% can never be read off two different credits.
     */
    private function salaryCredit(int $monthlyBasicCents): int
    {
        $floor = (int) config('cargo.payroll.sss.floor_cents', 500_000);
        $ceiling = (int) config('cargo.payroll.sss.ceiling_cents', 3_500_000);

        return max($floor, min($monthlyBasicCents, $ceiling));
    }

    /** 2.5% of the basic, held between a floor and a ceiling. */
    private function philhealth(int $monthlyBasicCents): int
    {
        $rate = (int) config('cargo.payroll.philhealth.employee_rate_bp', 250);

        // Nothing to contribute on nothing: an employee with no basic on record
        // — somebody paid per trip — should not be charged the floor.
        if ($monthlyBasicCents <= 0) {
            return 0;
        }

        return $this->percentOf($this->philhealthBasis($monthlyBasicCents), $rate);
    }

    /** The basic PhilHealth reads, between its floor and ceiling — both halves. */
    private function philhealthBasis(int $monthlyBasicCents): int
    {
        $floor = (int) config('cargo.payroll.philhealth.floor_cents', 1_000_000);
        $ceiling = (int) config('cargo.payroll.philhealth.ceiling_cents', 10_000_000);

        return max($floor, min($monthlyBasicCents, $ceiling));
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
     * ## Which table
     *
     * The one for the run's frequency, read off `$count` — how many runs the
     * month this run belongs to has:
     *
     *   **one** — the monthly table. A firm cutting off once a month used to
     *   be taxed on the semi-monthly table, which reads a month's pay as a
     *   fortnight's and roughly doubles the tax on anybody past the first
     *   bracket.
     *
     *   **two** — the semi-monthly table, as the BIR publishes it.
     *
     *   **three** — no BIR table describes 36 periods a year, so the period's
     *   pay is taken to a month, taxed on the monthly table, and divided back
     *   by the runs — the annualising the BIR prescribes for an irregular
     *   frequency. Closer than a fortnight's table on ten days' pay, which
     *   over-stated every payslip.
     *
     * Exemption is decided before this, in `for()`: the bracket looks at one
     * period's taxable pay, and the exemption at what the person earns.
     *
     * The brackets are read from the bottom up so the *highest* one that
     * applies wins; reading downwards and stopping at the first match is the
     * classic off-by-one that taxes a manager at 15%.
     */
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

    /**
     * One of the configured tables.
     *
     * A flat list is the shape this setting had before there was more than one
     * table, and it was the semi-monthly one — read that way so an install with
     * an overridden config keeps working until it is updated.
     *
     * @return array<int, array{over: int, base: int, rate_bp: int}>
     */
    private function table(string $frequency): array
    {
        $brackets = (array) config('cargo.payroll.withholding.brackets', []);

        if (array_is_list($brackets)) {
            return $brackets;
        }

        return (array) ($brackets[$frequency] ?? []);
    }

    /**
     * @param  array<int, array{over: int, base: int, rate_bp: int}>  $brackets
     */
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
