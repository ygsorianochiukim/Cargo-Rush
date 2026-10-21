<?php

declare(strict_types=1);

namespace App\Domain\Printing\Printables;

use App\Domain\Printing\Contracts\Printable;
use App\Domain\Printing\Printables\Concerns\DrawsBoxes;
use App\Domain\Printing\Printables\Concerns\ReadsPayRuns;
use App\Domain\Printing\Support\Format;
use App\Domain\Printing\Support\Layout as L;

class PayrollRegisterPrintable implements Printable
{
    use DrawsBoxes, ReadsPayRuns;

    public function key(): string
    {
        return 'payroll-register';
    }

    public function name(): string
    {
        return 'Payroll register';
    }

    public function group(): string
    {
        return 'Payroll';
    }

    public function description(): string
    {
        return 'A whole pay run: everybody on it, what was held back and what the company adds.';
    }

    public function orientation(): string
    {
        return 'landscape';
    }

    public function fields(): array
    {
        return [
            ...Format::companyFields(),
            ...self::runFields(),
            'run.approved' => 'Who approved it, and when (blank on a draft)',
            'run.journal' => 'Journal entry it was posted as (blank until paid)',
            'run.staff_count' => 'People paid',
            'run.gross' => 'Total earned',
            'run.deductions' => 'Total held back',
            'run.net' => 'Total take-home pay',
            'held.sss' => 'SSS held back',
            'held.philhealth' => 'PhilHealth held back',
            'held.pagibig' => 'Pag-IBIG held back',
            'held.tax' => 'Withholding tax held back',
            'held.other' => 'Advances recovered',
            'held.note' => 'Why no contributions were held (blank when they were)',
            'employer.sss' => 'Employer SSS',
            'employer.ec' => 'Employer EC',
            'employer.philhealth' => 'Employer PhilHealth',
            'employer.pagibig' => 'Employer Pag-IBIG',
            'employer.total' => 'Employer contributions in total',
            'today' => 'Date printed',
        ];
    }

    public function tables(): array
    {
        return [
            'register' => [
                ['key' => 'employee', 'label' => 'Employee'],
                ['key' => 'basic', 'label' => 'Basic', 'align' => 'right'],
                ['key' => 'allowance', 'label' => 'Allowance', 'align' => 'right'],
                ['key' => 'overtime', 'label' => 'Overtime', 'align' => 'right'],
                ['key' => 'adjustment', 'label' => 'Adjustment', 'align' => 'right'],
                ['key' => 'gross', 'label' => 'Earned', 'align' => 'right'],
                ['key' => 'sss', 'label' => 'SSS', 'align' => 'right'],
                ['key' => 'philhealth', 'label' => 'PhilHealth', 'align' => 'right'],
                ['key' => 'pagibig', 'label' => 'Pag-IBIG', 'align' => 'right'],
                ['key' => 'tax', 'label' => 'Tax', 'align' => 'right'],
                ['key' => 'advance', 'label' => 'Advance', 'align' => 'right'],
                ['key' => 'net', 'label' => 'Take-home', 'align' => 'right'],
            ],
        ];
    }

    public function defaults(): array
    {
        $muted = ['size' => 8.5, 'color' => L::MUTED];
        $caption = ['size' => 7, 'color' => L::MUTED, 'upper' => true];
        $small = ['size' => 7, 'color' => L::MUTED];

        $elements = [
            L::text('title', 'Title', 'top', 0, 0, 180, 'Pay for {{run.period}}', ['size' => 14, 'bold' => true]),
            L::text('state', 'Run status', 'top', 0, 7, 100, '{{run.state}}', ['size' => 7.5, 'bold' => true, 'upper' => true, 'color' => L::ACCENT]),
            L::text('details', 'Run details', 'top', 0, 11, 180, "{{run.reference}} · money out on {{run.pay_date}}\nApproved by {{run.approved}}\nIn the accounts as {{run.journal}}", $muted),
            L::text('company', 'Company name', 'top', 173, 0, 100, '{{company.name}}', ['size' => 9, 'color' => L::MUTED, 'align' => 'right']),
            L::line('rule_top', 'Line under header', 'top', 0, 25, 273),
        ];

        $tiles = [
            ['people', 'People paid', '{{run.staff_count}}', ''],
            ['gross', 'Total earned', '{{run.gross}}', 'what it costs the company'],
            ['held', 'Held back', '{{run.deductions}}', 'goes to the funds and the BIR'],
            ['net', 'Take-home pay', '{{run.net}}', 'what the staff receive'],
        ];

        foreach ($tiles as $i => [$id, $label, $value, $sub]) {
            $x = $i * 68.5;
            $elements[] = L::text("tile_{$id}_label", "{$label} label", 'top', $x, 28, 66, $label, $caption);
            $elements[] = L::text("tile_{$id}", $label, 'top', $x, 32, 66, $value, ['size' => 13, 'bold' => true, 'color' => $id === 'net' ? L::ACCENT : L::INK]);
            if ($sub !== '') {
                $elements[] = L::text("tile_{$id}_note", "{$label} note", 'top', $x, 38.5, 66, $sub, $small);
            }
        }

        $elements[] = L::table('register', 'Register', 'register', $this->tables()['register'], 1, ['title' => 'Register', 'size' => 7.5, 'space_before' => 0]);

        $elements[] = self::box('held_box', 'Held-back box', 'bottom', 0, 2, 272.4, 26.5);
        $elements[] = L::text('held_heading', 'Held-back heading', 'bottom', 3, 4, 266, 'Held back from the payslips — and who it goes to · {{run.deductions}}', $caption);

        $held = [
            ['sss', 'SSS', 'to Social Security System'],
            ['philhealth', 'PhilHealth', 'to PhilHealth'],
            ['pagibig', 'Pag-IBIG', 'to Pag-IBIG Fund'],
            ['tax', 'Withholding tax', 'to BIR'],
            ['other', 'Advances recovered', 'to the company'],
        ];

        foreach ($held as $i => [$key, $label, $to]) {
            $x = 3 + $i * 53.5;
            $elements[] = L::text("held_{$key}_label", "{$label} label", 'bottom', $x, 9, 52, $label, $caption);
            $elements[] = L::text("held_{$key}", $label, 'bottom', $x, 12.5, 52, "{{held.{$key}}}", ['size' => 10, 'bold' => true]);
            $elements[] = L::text("held_{$key}_to", "{$label} goes to", 'bottom', $x, 17.5, 52, $to, $small);
        }

        $elements[] = L::text('held_note', 'Held-back note', 'bottom', 3, 22.5, 266, '{{held.note}}', ['size' => 7.5]);

        $elements[] = self::box('employer_box', 'Employer box', 'bottom', 0, 31, 272.4, 21);
        $elements[] = L::text('employer_heading', 'Employer heading', 'bottom', 3, 33, 266, 'Employer contributions — paid by the company on top · {{employer.total}}', $caption);

        $employer = [
            ['sss', 'SSS', 'to Social Security System'],
            ['ec', 'EC', 'to SSS, for Employees’ Compensation'],
            ['philhealth', 'PhilHealth', 'to PhilHealth'],
            ['pagibig', 'Pag-IBIG', 'to Pag-IBIG Fund'],
        ];

        foreach ($employer as $i => [$key, $label, $to]) {
            $x = 3 + $i * 67;
            $elements[] = L::text("employer_{$key}_label", "Employer {$label} label", 'bottom', $x, 38, 64, $label, $caption);
            $elements[] = L::text("employer_{$key}", "Employer {$label}", 'bottom', $x, 41.5, 64, "{{employer.{$key}}}", ['size' => 10, 'bold' => true]);
            $elements[] = L::text("employer_{$key}_to", "Employer {$label} goes to", 'bottom', $x, 46.5, 64, $to, $small);
        }

        return L::make($elements, 46, 54, 12);
    }

    public function sample(): array
    {
        return $this->data($this->latestRun() ?? self::fakeRun());
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    public function data(array $run): array
    {
        $statutory = $run['statutory'];
        $employer = $run['employer_contributions'];
        $optional = static fn (?int $cents): string => $cents ? self::peso($cents) : '—';

        $values = [
            ...$this->runValues($run),
            'run.approved' => $run['approved_by_name'] ? $run['approved_by_name'].' on '.Format::date($run['approved_at']) : '',
            'run.journal' => (string) ($run['journal_reference'] ?? ''),
            'run.staff_count' => (string) $run['staff_count'],
            'run.gross' => self::peso($run['gross_cents']),
            'run.deductions' => self::peso($run['deductions_cents']),
            'run.net' => self::peso($run['net_cents']),
            'held.sss' => self::peso($statutory['sss']),
            'held.philhealth' => self::peso($statutory['philhealth']),
            'held.pagibig' => self::peso($statutory['pagibig']),
            'held.tax' => self::peso($statutory['withholding_tax']),
            'held.other' => self::peso($statutory['other']),
            'held.note' => $run['carries_contributions']
                ? ''
                : trim('No SSS, PhilHealth or Pag-IBIG on this run, and that is correct. '.$run['deduct_on_detail'].' The month is still remitted in full — it comes off the other payslip.'),
            'employer.sss' => self::peso($employer['sss']),
            'employer.ec' => self::peso($employer['ec']),
            'employer.philhealth' => self::peso($employer['philhealth']),
            'employer.pagibig' => self::peso($employer['pagibig']),
            'employer.total' => self::peso($employer['total']),
        ];

        $rows = array_map(static fn (array $row) => [
            'employee' => implode("\n", array_filter([
                $row['name'],
                implode(' · ', array_filter([$row['position'] ?? 'Staff', $row['employee_no'] ?? ''])),
            ])),
            'basic' => self::peso($row['basic_cents']),
            'allowance' => $optional($row['allowance_cents']),
            'overtime' => $optional($row['overtime_cents']),
            'adjustment' => $optional($row['adjustments_cents']),
            'gross' => self::peso($row['gross_cents']),
            'sss' => self::peso($row['sss_cents']),
            'philhealth' => self::peso($row['philhealth_cents']),
            'pagibig' => self::peso($row['pagibig_cents']),
            'tax' => self::peso($row['withholding_tax_cents']),
            'advance' => $optional($row['other_deductions_cents']),
            'net' => self::peso($row['net_cents']),
        ], $run['lines']);

        return [
            'pages' => [[
                'values' => $values,
                'tables' => [
                    'register' => [
                        'rows' => $rows,
                        'foot' => $rows === [] ? [] : [[
                            'employee' => 'Totals',
                            'gross' => self::peso($run['gross_cents']),
                            'sss' => self::peso($statutory['sss']),
                            'philhealth' => self::peso($statutory['philhealth']),
                            'pagibig' => self::peso($statutory['pagibig']),
                            'tax' => self::peso($statutory['withholding_tax']),
                            'advance' => self::peso($statutory['other']),
                            'net' => self::peso($run['net_cents']),
                        ]],
                    ],
                ],
            ]],
        ];
    }
}
