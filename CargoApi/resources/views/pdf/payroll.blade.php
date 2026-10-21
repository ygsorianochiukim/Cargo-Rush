<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $line ? 'Payslip' : 'Payroll' }} {{ $run['reference'] }}</title>
    <style>
        @page { margin: 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1b2430; }
        h1 { font-size: 17px; margin: 0; }
        h2 { font-size: 9px; margin: 14px 0 6px; text-transform: uppercase; letter-spacing: .05em; color: #6b7a8c; font-weight: normal; }
        .meta { color: #6b7a8c; }
        .kicker { font-size: 9px; font-weight: bold; letter-spacing: .14em; color: #15589c; text-transform: uppercase; }
        .pill { display: inline-block; font-size: 7.5px; font-weight: bold; text-transform: uppercase; letter-spacing: .06em; padding: 2px 6px; border-radius: 8px; background: #dff0ff; color: #15589c; vertical-align: middle; }
        .pill.paid { background: #e3f5ea; color: #1e7a45; }
        .pill.draft { background: #fdf1dc; color: #9a5b00; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-weight: normal; color: #6b7a8c; border-bottom: 1px solid #b9c2cd; padding: 4px 4px; font-size: 8px; text-transform: uppercase; }
        td { border-bottom: 1px solid #e6eaef; padding: 4px 4px; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .strong { font-weight: bold; }
        .head { width: 100%; border-bottom: 1px solid #d5dbe2; padding-bottom: 8px; }
        .head td { border: 0; padding: 0; }
        .tiles td { border: 0; padding: 8px 4px 0 0; width: 25%; }
        .tiles .label, .cells .label { color: #6b7a8c; font-size: 8px; text-transform: uppercase; }
        .tiles .value { font-size: 15px; font-weight: bold; margin-top: 2px; }
        .cells td { border: 0; padding: 4px 6px 4px 0; }
        .cells .value { font-size: 11px; font-weight: bold; margin-top: 2px; }
        .box { border: 1px solid #e6eaef; padding: 8px 10px; margin-top: 10px; page-break-inside: avoid; }
        .box h2 { margin-top: 0; }
        tfoot td { border-top: 2px solid #b9c2cd; border-bottom: 0; font-weight: bold; }
        .slip dl { margin: 0; }
        .slip td { border-bottom: 0; padding: 3px 0; }
        .slip .total td { border-top: 1px solid #b9c2cd; font-weight: bold; padding-top: 5px; }
        .takehome { border-top: 2px solid #b9c2cd; margin-top: 14px; padding-top: 10px; page-break-inside: avoid; }
        .sign td { border: 0; padding: 40px 20px 0 0; width: 50%; }
        .sign div { border-top: 1px solid #8a96a5; padding-top: 4px; color: #6b7a8c; }
        .small { font-size: 8.5px; }
    </style>
</head>
<body>
@if ($line)
    <table class="head">
        <tr>
            <td>
                <div class="kicker">Payslip</div>
                <h1 style="margin-top: 3px;">{{ $line['name'] }}</h1>
                <div class="meta">{{ $line['position'] ?? 'Staff' }}@if ($line['employee_no']) · {{ $line['employee_no'] }}@endif</div>
            </td>
            <td style="text-align: right;">
                <div class="strong" style="font-size: 11px;">{{ $run['reference'] }}</div>
                <div class="meta">{{ $run['period_label'] }}</div>
                <div class="meta">Paid {{ $date($run['pay_date']) }}</div>
                <div class="meta">{{ $company }}</div>
            </td>
        </tr>
    </table>

    <table class="slip" style="margin-top: 10px;">
        <tr>
            <td style="width: 48%; padding-right: 4%; vertical-align: top;">
                <h2>What you earned</h2>
                <table>
                    <tr>
                        <td>
                            @switch($line['pay_basis'])
                                @case('per_trip')
                                    Trip pay
                                    <div class="meta small">{{ $line['trips'] }} {{ $line['trips'] === 1 ? 'haul' : 'hauls' }} delivered · {{ $line['sheet_days'] }} {{ $line['sheet_days'] === 1 ? 'day' : 'days' }} on the truck sheet</div>
                                    @break
                                @case('daily')
                                    Daily pay
                                    <div class="meta small">{{ $line['days_worked'] }} {{ $line['days_worked'] === 1 ? 'day' : 'days' }} worked</div>
                                    @break
                                @default
                                    Basic pay
                            @endswitch
                        </td>
                        <td class="num">{{ $peso($line['basic_cents']) }}</td>
                    </tr>
                    @foreach ($line['components'] as $component)
                        @if ($component['kind'] === 'earning')
                            <tr><td>{{ $component['name'] }}</td><td class="num">{{ $peso($component['amount_cents']) }}</td></tr>
                        @endif
                    @endforeach
                    @if ($line['allowance_cents'])
                        <tr><td>Allowance</td><td class="num">{{ $peso($line['allowance_cents']) }}</td></tr>
                    @endif
                    @if ($line['overtime_cents'])
                        <tr><td>Overtime</td><td class="num">{{ $peso($line['overtime_cents']) }}</td></tr>
                    @endif
                    @if ($line['adjustments_cents'])
                        <tr>
                            <td>Adjustment @if ($line['adjustment_note'])<div class="meta small">{{ $line['adjustment_note'] }}</div>@endif</td>
                            <td class="num">{{ $peso($line['adjustments_cents']) }}</td>
                        </tr>
                    @endif
                    <tr class="total"><td>Total earned</td><td class="num">{{ $peso($line['gross_cents']) }}</td></tr>
                </table>
            </td>
            <td style="width: 48%; vertical-align: top;">
                <h2>What was held back</h2>
                <table>
                    <tr><td>SSS</td><td class="num">{{ $peso($line['sss_cents']) }}</td></tr>
                    <tr><td>PhilHealth</td><td class="num">{{ $peso($line['philhealth_cents']) }}</td></tr>
                    <tr><td>Pag-IBIG</td><td class="num">{{ $peso($line['pagibig_cents']) }}</td></tr>
                    <tr><td>Withholding tax</td><td class="num">{{ $peso($line['withholding_tax_cents']) }}</td></tr>
                    @foreach ($line['components'] as $component)
                        @if ($component['kind'] === 'deduction')
                            <tr><td>{{ $component['name'] }}</td><td class="num">{{ $peso($component['amount_cents']) }}</td></tr>
                        @endif
                    @endforeach
                    @if ($line['other_deductions_cents'])
                        <tr>
                            <td>Advance or other @if ($line['deduction_note'])<div class="meta small">{{ $line['deduction_note'] }}</div>@endif</td>
                            <td class="num">{{ $peso($line['other_deductions_cents']) }}</td>
                        </tr>
                    @endif
                    <tr class="total"><td>Total held back</td><td class="num">{{ $peso($line['deductions_cents']) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="takehome">
        <table>
            <tr>
                <td style="border: 0;" class="meta">TAKE-HOME PAY</td>
                <td style="border: 0; font-size: 20px;" class="num strong">{{ $peso($line['net_cents']) }}</td>
            </tr>
        </table>
        @if ($line['employer_contributions_cents'])
            <p class="meta small" style="margin: 6px 0 0;">
                Employer contributions (not deducted from pay): {{ $peso($line['employer_contributions_cents']) }}
                — SSS {{ $peso($line['employer_sss_cents']) }}, EC {{ $peso($line['employer_ec_cents']) }},
                PhilHealth {{ $peso($line['employer_philhealth_cents']) }}, Pag-IBIG {{ $peso($line['employer_pagibig_cents']) }}.
            </p>
        @endif
    </div>

    <table class="sign">
        <tr>
            <td><div>Received by — {{ $line['name'] }}</div></td>
            <td><div>Prepared by</div></td>
        </tr>
    </table>
@else
    <table class="head">
        <tr>
            <td>
                <h1>Pay for {{ $run['period_label'] }} <span class="pill {{ $run['status'] === 'paid' ? 'paid' : ($run['status'] === 'approved' ? '' : 'draft') }}">{{ $state }}</span></h1>
                <div class="meta" style="margin-top: 2px;">{{ $run['reference'] }} · money out on {{ $date($run['pay_date']) }}</div>
                @if ($run['approved_by_name'])
                    <div class="meta small">Approved by {{ $run['approved_by_name'] }} on {{ $date($run['approved_at']) }}</div>
                @endif
                @if ($run['journal_reference'])
                    <div class="meta small">In the accounts as {{ $run['journal_reference'] }}</div>
                @endif
            </td>
            <td style="text-align: right;" class="meta">{{ $company }}</td>
        </tr>
    </table>

    <table class="tiles">
        <tr>
            <td><div class="label">People paid</div><div class="value">{{ $run['staff_count'] }}</div></td>
            <td><div class="label">Total earned</div><div class="value">{{ $peso($run['gross_cents']) }}</div><div class="meta small">what it costs the company</div></td>
            <td><div class="label">Held back</div><div class="value">{{ $peso($run['deductions_cents']) }}</div><div class="meta small">goes to the funds and the BIR</div></td>
            <td><div class="label">Take-home pay</div><div class="value" style="color: #15589c;">{{ $peso($run['net_cents']) }}</div><div class="meta small">what the staff receive</div></td>
        </tr>
    </table>

    <h2>Register</h2>
    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th class="num">Basic</th>
                <th class="num">Allowance</th>
                <th class="num">Overtime</th>
                <th class="num">Adjustment</th>
                <th class="num">Earned</th>
                <th class="num">SSS</th>
                <th class="num">PhilHealth</th>
                <th class="num">Pag-IBIG</th>
                <th class="num">Tax</th>
                <th class="num">Advance</th>
                <th class="num">Take-home</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($run['lines'] as $row)
                <tr>
                    <td>
                        <span class="strong">{{ $row['name'] }}</span>
                        <div class="meta small">{{ $row['position'] ?? 'Staff' }}@if ($row['employee_no']) · {{ $row['employee_no'] }}@endif</div>
                    </td>
                    <td class="num">{{ $peso($row['basic_cents']) }}</td>
                    <td class="num">{{ $row['allowance_cents'] ? $peso($row['allowance_cents']) : '—' }}</td>
                    <td class="num">{{ $row['overtime_cents'] ? $peso($row['overtime_cents']) : '—' }}</td>
                    <td class="num">{{ $row['adjustments_cents'] ? $peso($row['adjustments_cents']) : '—' }}</td>
                    <td class="num strong">{{ $peso($row['gross_cents']) }}</td>
                    <td class="num">{{ $peso($row['sss_cents']) }}</td>
                    <td class="num">{{ $peso($row['philhealth_cents']) }}</td>
                    <td class="num">{{ $peso($row['pagibig_cents']) }}</td>
                    <td class="num">{{ $peso($row['withholding_tax_cents']) }}</td>
                    <td class="num">{{ $row['other_deductions_cents'] ? $peso($row['other_deductions_cents']) : '—' }}</td>
                    <td class="num strong">{{ $peso($row['net_cents']) }}</td>
                </tr>
            @empty
                <tr><td colspan="12" class="meta">Nobody is on this run. Only active employees with a monthly salary on record are paid here — a driver paid per trip is already in the daily truck sheet.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td>Totals</td>
                <td colspan="4"></td>
                <td class="num">{{ $peso($run['gross_cents']) }}</td>
                <td class="num">{{ $peso($run['statutory']['sss']) }}</td>
                <td class="num">{{ $peso($run['statutory']['philhealth']) }}</td>
                <td class="num">{{ $peso($run['statutory']['pagibig']) }}</td>
                <td class="num">{{ $peso($run['statutory']['withholding_tax']) }}</td>
                <td class="num">{{ $peso($run['statutory']['other']) }}</td>
                <td class="num">{{ $peso($run['net_cents']) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="box">
        <h2>Held back from the payslips — and who it goes to · {{ $peso($run['deductions_cents']) }}</h2>
        <table class="cells">
            <tr>
                @foreach ([
                    ['SSS', 'Social Security System', $run['statutory']['sss']],
                    ['PhilHealth', 'PhilHealth', $run['statutory']['philhealth']],
                    ['Pag-IBIG', 'Pag-IBIG Fund', $run['statutory']['pagibig']],
                    ['Withholding tax', 'BIR', $run['statutory']['withholding_tax']],
                    ['Advances recovered', 'the company', $run['statutory']['other']],
                ] as [$label, $goesTo, $cents])
                    <td><div class="label">{{ $label }}</div><div class="value">{{ $peso($cents) }}</div><div class="meta small">to {{ $goesTo }}</div></td>
                @endforeach
            </tr>
        </table>
        @if (! $run['carries_contributions'])
            <p class="small" style="margin: 6px 0 0;">No SSS, PhilHealth or Pag-IBIG on this run, and that is correct. {{ $run['deduct_on_detail'] }} The month is still remitted in full — it comes off the other payslip.</p>
        @endif
    </div>

    <div class="box">
        <h2>Employer contributions — paid by the company on top · {{ $peso($run['employer_contributions']['total']) }}</h2>
        <table class="cells">
            <tr>
                @foreach ([
                    ['SSS', 'Social Security System', $run['employer_contributions']['sss']],
                    ['EC', 'SSS, for Employees’ Compensation', $run['employer_contributions']['ec']],
                    ['PhilHealth', 'PhilHealth', $run['employer_contributions']['philhealth']],
                    ['Pag-IBIG', 'Pag-IBIG Fund', $run['employer_contributions']['pagibig']],
                ] as [$label, $goesTo, $cents])
                    <td><div class="label">{{ $label }}</div><div class="value">{{ $peso($cents) }}</div><div class="meta small">to {{ $goesTo }}</div></td>
                @endforeach
            </tr>
        </table>
    </div>
@endif
</body>
</html>
