<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Services\TripPayService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What payroll cost a period, and what it still owes — for Finance, read-only.
 *
 * Payroll posts itself to the general journal, but the period reports
 * (Profitability, the Quarterly Summary, the dashboard) are built from the
 * daily sheet, and a paid run was on none of it. An office that paid ₱180,000
 * of wages in a quarter read a quarter that had paid nothing but the crew
 * columns.
 *
 * ## Counting each peso once: the sheet first, payroll for the rest
 *
 * The sheet's `driver_salary_cents` and helper lines are what the office
 * recorded paying the crew that day. A daily or per-trip hand on a pay run is
 * paid for the same days — `TripPayService` reads the very same rows — so
 * adding the run's gross on top of the sheet would charge the fleet twice for
 * one fortnight of driving.
 *
 * The scheme, per payslip:
 *
 *     counted = max(0, gross − what the sheet already recorded for that
 *                       person over the run's period)
 *
 * so the sheet keeps what it has (every truck row still adds up, and the crew
 * columns are unchanged), and payroll adds only what the sheet never saw — an
 * office clerk's salary, a driver's rate-card pay above what was typed on the
 * sheet, the statutory contributions inside the gross. A person the sheet
 * recorded more for than the run paid adds nothing: the sheet has the larger
 * figure already. Across the two, what is counted is the larger of the two
 * records of the same work, never their sum.
 *
 * Dated by the **pay date** (the day the money left, falling back to when the
 * run was marked paid), on the same cash footing as a paid supplier bill. Only
 * `paid` runs: an approved run has cost nothing yet, and is owed — see below.
 *
 * **Employer contributions.** The employee's share of SSS, PhilHealth and
 * Pag-IBIG is inside the gross. The firm's own share (and EC) is not — it is
 * paid on top — and the daily sheet never records it either, so it is added
 * to `counted` whole, beside the wages:
 *
 *     counted = Σ max(0, gross − sheet) + employer contributions
 *
 * and it is owed to the agencies, with what was withheld, until remitted.
 *
 * ## What payroll still owes
 *
 * Two debts, both on the Payables page and in `payables_cents`:
 *
 *   an **approved** run's net pay, until it is marked paid;
 *
 *   the **withheld** contributions and tax on a paid run, and the employer
 *   share on top of them, owed to the agencies. There is no remittance
 *   record, so they are treated as owed from the pay date to the end of the
 *   month after the period closed — the latest an agency would have them —
 *   and remitted after that. An office that remits early reads a debt a few
 *   weeks longer than it lasted, which is the safe direction.
 *
 * The withheld amounts are part of a paid run's gross and the employer share
 * is counted beside it, so both are already an expense; `costedOwedAsOf()`
 * says how much of what is owed is already in the expenses, so
 * `actual_income` takes it off once.
 */
class PayrollCostService
{
    public function __construct(private readonly TripPayService $tripPay) {}

    /**
     * Paid runs dated inside the window, each with what Finance counts of it.
     *
     * `wages_cents` is the gross beyond the sheet, `employer_cents` the firm's
     * contributions on top, and `counted_cents` the two together — what the
     * roll-up adds.
     *
     * @return Collection<int, array{run: PayRun, date: string, gross_cents: int, sheet_cents: int, wages_cents: int, employer_cents: int, counted_cents: int}>
     */
    public function paidBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return PayRun::query()
            ->with('lines.employee')
            ->where('status', PayRun::PAID)
            ->get()
            ->filter(fn (PayRun $run): bool => $this->within($this->paidOn($run), $from, $to))
            ->map(function (PayRun $run): array {
                $sheet = $this->sheetPayByLine($run);

                $wages = (int) $run->lines->sum(static fn (PayRunLine $line): int => max(
                    0,
                    (int) $line->gross_cents - ($sheet[$line->getKey()] ?? 0),
                ));

                // Never on the sheet, so never netted against it.
                $employer = $run->employerContributionsCents()['total'];

                return [
                    'run' => $run,
                    'date' => $this->paidOn($run)->toDateString(),
                    'gross_cents' => $run->grossCents(),
                    'sheet_cents' => (int) array_sum($sheet),
                    'wages_cents' => $wages,
                    'employer_cents' => $employer,
                    'counted_cents' => $wages + $employer,
                ];
            })
            ->sortBy('date')
            ->values();
    }

    /** The same, as one figure. */
    public function costBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) $this->paidBetween($from, $to)->sum('counted_cents');
    }

    /**
     * What payroll owed as at a date, as payables lines.
     *
     * @return array<int, array{id: string, name: string, detail: string, amount_cents: int, costed_cents: int, due_on: ?string}>
     */
    public function owedAsOf(CarbonInterface $asOf): array
    {
        $day = Carbon::parse($asOf->toDateString())->endOfDay();
        $lines = [];

        $runs = PayRun::query()
            ->with('lines.employee')
            ->whereIn('status', [PayRun::APPROVED, PayRun::PAID])
            ->get();

        foreach ($runs as $run) {
            $approvedBy = $run->approved_at !== null && $run->approved_at->lessThanOrEqualTo($day);
            $paidBy = $run->isPaid() && $this->paidOn($run)->lessThanOrEqualTo($day);

            // Signed off and not yet paid on that day: the staff are owed the
            // net. What the sheet already charged for the same people is in
            // the expenses already, and is marked so.
            if ($approvedBy && ! $paidBy) {
                $sheet = $this->sheetPayByLine($run);

                $lines[] = [
                    'id' => $run->getKey(),
                    'name' => "Payroll {$run->reference}",
                    'detail' => 'Net pay, approved and not yet paid · '.$run->periodLabel(),
                    'amount_cents' => $run->netCents(),
                    'costed_cents' => (int) $run->lines->sum(static fn (PayRunLine $line): int => min(
                        max(0, (int) $line->net_cents),
                        $sheet[$line->getKey()] ?? 0,
                    )),
                    'due_on' => $run->pay_date?->toDateString(),
                ];
            }

            /*
             * Owed to the agencies from the day it was paid until it was
             * remitted — the recorded day when the office has said, the
             * deadline when it has not (see the class note).
             */
            $stillOwed = $run->isRemitted()
                ? $day->toDateString() < $run->remitted_on->toDateString()
                : $day->lessThanOrEqualTo($this->remittedBy($run));

            if ($paidBy && $stillOwed) {
                $withheld = $this->withheldCents($run);

                if ($withheld > 0) {
                    $lines[] = [
                        'id' => $run->getKey().':statutory',
                        'name' => "Payroll {$run->reference}",
                        'detail' => 'SSS, PhilHealth, Pag-IBIG and withholding tax withheld, plus the employer share, to remit · '.$run->periodLabel(),
                        'amount_cents' => $withheld,
                        // The withheld part is inside the run's gross and the
                        // employer share is counted beside it — both already
                        // an expense of the period the run was paid in.
                        'costed_cents' => $withheld,
                        'due_on' => $this->remittedBy($run)->toDateString(),
                    ];
                }
            }
        }

        return $lines;
    }

    /** What payroll owed at a date, as one figure. */
    public function owedCentsAsOf(CarbonInterface $asOf): int
    {
        return (int) array_sum(array_column($this->owedAsOf($asOf), 'amount_cents'));
    }

    /** Of that, what is already inside the period's expenses. */
    public function costedOwedAsOf(CarbonInterface $asOf): int
    {
        return (int) array_sum(array_column($this->owedAsOf($asOf), 'costed_cents'));
    }

    /**
     * What a paid run owes the agencies: the contributions and tax withheld,
     * and the firm's own share on top — remitted on the same forms, on the
     * same deadline, so owed on the same terms.
     */
    private function withheldCents(PayRun $run): int
    {
        return $run->owedToAgenciesCents();
    }

    /** The day the money left: the pay date, or the day it was marked paid. */
    private function paidOn(PayRun $run): Carbon
    {
        return Carbon::parse(($run->pay_date ?? $run->paid_at ?? $run->updated_at ?? now())->toDateString());
    }

    /** The end of the month after the period closed — see the class note. */
    private function remittedBy(PayRun $run): Carbon
    {
        return $run->remittanceDueOn();
    }

    private function within(Carbon $day, CarbonInterface $from, CarbonInterface $to): bool
    {
        return $day->toDateString() >= $from->toDateString()
            && $day->toDateString() <= $to->toDateString();
    }

    /**
     * What the daily sheet recorded paying each payslip's person over the
     * run's period, keyed by line id.
     *
     * @return array<string, int>
     */
    private function sheetPayByLine(PayRun $run): array
    {
        if ($run->period_start === null || $run->period_end === null) {
            return [];
        }

        $employees = $run->lines->pluck('employee')->filter()->unique('id')->values();

        if ($employees->isEmpty()) {
            return [];
        }

        $sheet = $this->tripPay->forEmployees($employees, $run->period_start, $run->period_end);

        $byLine = [];

        foreach ($run->lines as $line) {
            $byLine[$line->getKey()] = (int) ($sheet[$line->employee_id]['earned_cents'] ?? 0);
        }

        return $byLine;
    }
}
