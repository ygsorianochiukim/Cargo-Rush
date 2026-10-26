<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Services\TripPayService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PayrollCostService
{
    public function __construct(private readonly TripPayService $tripPay) {}

    /** @return Collection<int, array{run: PayRun, date: string, gross_cents: int, sheet_cents: int, wages_cents: int, employer_cents: int, counted_cents: int}> */
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

    public function costBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) $this->paidBetween($from, $to)->sum('counted_cents');
    }

    /** @return array<int, array{id: string, name: string, detail: string, amount_cents: int, costed_cents: int, due_on: ?string}> */
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
                    'settle_at' => $run->scope()->route(),
                ];
            }

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
                        'costed_cents' => $withheld,
                        'due_on' => $this->remittedBy($run)->toDateString(),
                    ];
                }
            }
        }

        return $lines;
    }

    public function owedCentsAsOf(CarbonInterface $asOf): int
    {
        return (int) array_sum(array_column($this->owedAsOf($asOf), 'amount_cents'));
    }

    public function costedOwedAsOf(CarbonInterface $asOf): int
    {
        return (int) array_sum(array_column($this->owedAsOf($asOf), 'costed_cents'));
    }

    private function withheldCents(PayRun $run): int
    {
        return $run->owedToAgenciesCents();
    }

    private function paidOn(PayRun $run): Carbon
    {
        return Carbon::parse(($run->pay_date ?? $run->paid_at ?? $run->updated_at ?? now())->toDateString());
    }

    private function remittedBy(PayRun $run): Carbon
    {
        return $run->remittanceDueOn();
    }

    private function within(Carbon $day, CarbonInterface $from, CarbonInterface $to): bool
    {
        return $day->toDateString() >= $from->toDateString()
            && $day->toDateString() <= $to->toDateString();
    }

    /** @return array<string, int> */
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
