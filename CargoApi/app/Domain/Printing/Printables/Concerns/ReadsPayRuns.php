<?php

declare(strict_types=1);

namespace App\Domain\Printing\Printables\Concerns;

use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Resources\PayRunResource;
use App\Domain\Payroll\Services\PayrollService;
use App\Domain\Printing\Support\Format;

trait ReadsPayRuns
{
    protected static function peso(?int $cents): string
    {
        $cents ??= 0;

        return ($cents < 0 ? '-' : '').'₱'.number_format(abs($cents) / 100, 2);
    }

    /** @return array<string, mixed>|null */
    protected function latestRun(): ?array
    {
        $run = PayRun::query()->has('lines')->latest('period_end')->first();

        if ($run === null) {
            return null;
        }

        return (new PayRunResource(app(PayrollService::class)->find($run->getKey())))->resolve(request());
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, string>
     */
    protected function runValues(array $run): array
    {
        return [
            ...Format::companyValues(),
            'run.reference' => (string) $run['reference'],
            'run.period' => (string) $run['period_label'],
            'run.pay_date' => Format::date($run['pay_date']),
            'run.state' => match ($run['status']) {
                'paid' => 'Paid',
                'approved' => 'Approved · not yet paid',
                default => 'Draft',
            },
        ];
    }

    /** @return array<string, string> */
    protected static function runFields(): array
    {
        return [
            'run.reference' => 'Pay run reference',
            'run.period' => 'Pay period',
            'run.pay_date' => 'Pay date',
            'run.state' => 'Run status (Draft, Approved, Paid)',
        ];
    }

    /** @return array<string, mixed> */
    protected static function fakeLine(): array
    {
        return [
            'id' => 'sample',
            'name' => 'Juan Dela Cruz',
            'employee_no' => 'EMP-0001',
            'position' => 'Office Staff',
            'pay_basis' => 'monthly',
            'trips' => 0,
            'sheet_days' => 0,
            'days_worked' => 0,
            'basic_cents' => 1_500_000,
            'allowance_cents' => 100_000,
            'overtime_cents' => 0,
            'adjustments_cents' => 0,
            'adjustment_note' => null,
            'gross_cents' => 1_600_000,
            'sss_cents' => 75_000,
            'philhealth_cents' => 37_500,
            'pagibig_cents' => 20_000,
            'withholding_tax_cents' => 0,
            'other_deductions_cents' => 0,
            'deduction_note' => null,
            'deductions_cents' => 132_500,
            'net_cents' => 1_467_500,
            'components' => [],
            'employer_contributions_cents' => 167_000,
            'employer_sss_cents' => 142_000,
            'employer_ec_cents' => 3_000,
            'employer_philhealth_cents' => 37_500,
            'employer_pagibig_cents' => 20_000,
        ];
    }

    /** @return array<string, mixed> */
    protected static function fakeRun(): array
    {
        $line = self::fakeLine();

        return [
            'reference' => 'PR-'.now()->year.'-0001',
            'period_label' => now()->startOfMonth()->format('M j').'–'.now()->startOfMonth()->addDays(14)->format('j, Y'),
            'pay_date' => now()->startOfMonth()->addDays(14)->toDateString(),
            'status' => 'draft',
            'approved_by_name' => null,
            'approved_at' => null,
            'journal_reference' => null,
            'staff_count' => 1,
            'gross_cents' => $line['gross_cents'],
            'deductions_cents' => $line['deductions_cents'],
            'net_cents' => $line['net_cents'],
            'lines' => [$line],
            'statutory' => ['sss' => 75_000, 'philhealth' => 37_500, 'pagibig' => 20_000, 'withholding_tax' => 0, 'other' => 0],
            'carries_contributions' => true,
            'deduct_on_detail' => '',
            'employer_contributions' => ['sss' => 142_000, 'ec' => 3_000, 'philhealth' => 37_500, 'pagibig' => 20_000, 'total' => 202_500],
        ];
    }
}
