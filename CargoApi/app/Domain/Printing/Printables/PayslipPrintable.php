<?php

declare(strict_types=1);

namespace App\Domain\Printing\Printables;

use App\Domain\Printing\Contracts\Printable;
use App\Domain\Printing\Printables\Concerns\ReadsPayRuns;
use App\Domain\Printing\Support\Format;
use App\Domain\Printing\Support\Layout as L;

class PayslipPrintable implements Printable
{
    use ReadsPayRuns;

    public function key(): string
    {
        return 'payslip';
    }

    public function name(): string
    {
        return 'Payslip';
    }

    public function group(): string
    {
        return 'Payroll';
    }

    public function description(): string
    {
        return 'One person’s pay for a run: what they earned, what was held back, and what they take home.';
    }

    public function orientation(): string
    {
        return 'portrait';
    }

    public function fields(): array
    {
        return [
            ...Format::companyFields(),
            ...self::runFields(),
            'employee.name' => 'Employee name',
            'employee.position' => 'Position',
            'employee.no' => 'Employee number',
            'employee.details' => 'Position and employee number together',
            'pay.gross' => 'Total earned',
            'pay.deductions' => 'Total held back',
            'pay.net' => 'Take-home pay',
            'employer.total' => 'Employer contributions in total (blank when none)',
            'employer.sss' => 'Employer SSS (blank when none)',
            'employer.ec' => 'Employer EC (blank when none)',
            'employer.philhealth' => 'Employer PhilHealth (blank when none)',
            'employer.pagibig' => 'Employer Pag-IBIG (blank when none)',
            'today' => 'Date printed',
        ];
    }

    public function tables(): array
    {
        return [
            'earnings' => [
                ['key' => 'item', 'label' => 'Item'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right'],
            ],
            'deductions' => [
                ['key' => 'item', 'label' => 'Item'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right'],
            ],
        ];
    }

    public function defaults(): array
    {
        $muted = ['size' => 8.5, 'color' => L::MUTED];
        $caption = ['size' => 7, 'color' => L::MUTED, 'upper' => true];
        $tables = $this->tables();

        return L::make([
            L::text('kicker', 'Title', 'top', 0, 0, 100, 'Payslip', ['size' => 8, 'bold' => true, 'upper' => true, 'color' => L::ACCENT]),
            L::text('employee_name', 'Employee name', 'top', 0, 4.5, 110, '{{employee.name}}', ['size' => 14, 'bold' => true]),
            L::text('employee_details', 'Employee details', 'top', 0, 11.5, 110, '{{employee.details}}', $muted),
            L::text('run_reference', 'Run reference', 'top', 96, 0, 90, '{{run.reference}}', ['size' => 10, 'bold' => true, 'align' => 'right']),
            L::text('run_details', 'Run details', 'top', 96, 5, 90, "{{run.period}}\nPaid {{run.pay_date}}\n{{company.name}}", ['size' => 8.5, 'color' => L::MUTED, 'align' => 'right']),
            L::line('rule_top', 'Line under header', 'top', 0, 23.5, 186),

            L::table('earnings', 'What you earned', 'earnings', $tables['earnings'], 1, ['title' => 'What you earned', 'space_before' => 0, 'size' => 9]),
            L::table('deductions', 'What was held back', 'deductions', $tables['deductions'], 2, ['title' => 'What was held back', 'space_before' => 6, 'size' => 9]),

            L::line('rule_takehome', 'Line above take-home pay', 'bottom', 0, 2, 186, '#b9c2cd', 0.6),
            L::text('takehome_label', 'Take-home pay label', 'bottom', 0, 7, 90, 'Take-home pay', $caption),
            L::text('takehome', 'Take-home pay', 'bottom', 96, 4.5, 90, '{{pay.net}}', ['size' => 18, 'bold' => true, 'align' => 'right']),
            L::text('employer_note', 'Employer contributions note', 'bottom', 0, 14, 186, 'Employer contributions (not deducted from pay): {{employer.total}} — SSS {{employer.sss}}, EC {{employer.ec}}, PhilHealth {{employer.philhealth}}, Pag-IBIG {{employer.pagibig}}.', ['size' => 7.5, 'color' => L::MUTED]),
            L::line('received_line', 'Received by line', 'bottom', 0, 34, 85, '#8a96a5'),
            L::text('received_by', 'Received by', 'bottom', 0, 35, 85, 'Received by — {{employee.name}}', $muted),
            L::line('prepared_line', 'Prepared by line', 'bottom', 101, 34, 85, '#8a96a5'),
            L::text('prepared_by', 'Prepared by', 'bottom', 101, 35, 85, 'Prepared by', $muted),
        ], 27, 42, 12);
    }

    public function sample(): array
    {
        $run = $this->latestRun();

        return $run === null
            ? $this->data(self::fakeRun(), [self::fakeLine()])
            : $this->data($run, [$run['lines'][0]]);
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    public function data(array $run, array $lines): array
    {
        return [
            'pages' => array_map(fn (array $line) => $this->page($run, $line), array_values($lines)),
        ];
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function page(array $run, array $line): array
    {
        $plural = static fn (int $n, string $one, string $many): string => $n.' '.($n === 1 ? $one : $many);

        $basic = match ($line['pay_basis']) {
            'per_trip' => "Trip pay\n".$plural((int) $line['trips'], 'haul', 'hauls').' delivered · '.$plural((int) $line['sheet_days'], 'day', 'days').' on the truck sheet',
            'daily' => "Daily pay\n".$plural((int) $line['days_worked'], 'day', 'days').' worked',
            default => 'Basic pay',
        };

        $earnings = [['item' => $basic, 'amount' => self::peso($line['basic_cents'])]];
        $deductions = [
            ['item' => 'SSS', 'amount' => self::peso($line['sss_cents'])],
            ['item' => 'PhilHealth', 'amount' => self::peso($line['philhealth_cents'])],
            ['item' => 'Pag-IBIG', 'amount' => self::peso($line['pagibig_cents'])],
            ['item' => 'Withholding tax', 'amount' => self::peso($line['withholding_tax_cents'])],
        ];

        foreach ($line['components'] ?? [] as $component) {
            $row = ['item' => (string) $component['name'], 'amount' => self::peso($component['amount_cents'])];

            if ($component['kind'] === 'earning') {
                $earnings[] = $row;
            } elseif ($component['kind'] === 'deduction') {
                $deductions[] = $row;
            }
        }

        if ($line['allowance_cents']) {
            $earnings[] = ['item' => 'Allowance', 'amount' => self::peso($line['allowance_cents'])];
        }

        if ($line['overtime_cents']) {
            $earnings[] = ['item' => 'Overtime', 'amount' => self::peso($line['overtime_cents'])];
        }

        if ($line['adjustments_cents']) {
            $earnings[] = ['item' => trim("Adjustment\n".($line['adjustment_note'] ?? '')), 'amount' => self::peso($line['adjustments_cents'])];
        }

        if ($line['other_deductions_cents']) {
            $deductions[] = ['item' => trim("Advance or other\n".($line['deduction_note'] ?? '')), 'amount' => self::peso($line['other_deductions_cents'])];
        }

        $employer = (int) ($line['employer_contributions_cents'] ?? 0) > 0;

        return [
            'values' => [
                ...$this->runValues($run),
                'employee.name' => (string) $line['name'],
                'employee.position' => (string) ($line['position'] ?? 'Staff'),
                'employee.no' => (string) ($line['employee_no'] ?? ''),
                'employee.details' => implode(' · ', array_filter([$line['position'] ?? 'Staff', $line['employee_no'] ?? ''])),
                'pay.gross' => self::peso($line['gross_cents']),
                'pay.deductions' => self::peso($line['deductions_cents']),
                'pay.net' => self::peso($line['net_cents']),
                'employer.total' => $employer ? self::peso($line['employer_contributions_cents']) : '',
                'employer.sss' => $employer ? self::peso($line['employer_sss_cents']) : '',
                'employer.ec' => $employer ? self::peso($line['employer_ec_cents']) : '',
                'employer.philhealth' => $employer ? self::peso($line['employer_philhealth_cents']) : '',
                'employer.pagibig' => $employer ? self::peso($line['employer_pagibig_cents']) : '',
            ],
            'tables' => [
                'earnings' => ['rows' => $earnings, 'foot' => [['item' => 'Total earned', 'amount' => self::peso($line['gross_cents'])]]],
                'deductions' => ['rows' => $deductions, 'foot' => [['item' => 'Total held back', 'amount' => self::peso($line['deductions_cents'])]]],
            ],
        ];
    }
}
