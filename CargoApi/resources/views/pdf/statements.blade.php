<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Financial Statement</title>
    <style>
        @page { margin: 36px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1b2430; }
        .kicker { font-size: 8.5px; font-weight: bold; letter-spacing: .14em; text-transform: uppercase; color: #15589c; }
        h1 { font-size: 17px; margin: 3px 0 0; }
        .company { color: #6b7a8c; margin-top: 2px; }
        .head { border-bottom: 1px solid #d5dbe2; padding-bottom: 10px; margin-bottom: 4px; }
        h2 { font-size: 8.5px; text-transform: uppercase; letter-spacing: .06em; color: #6b7a8c; margin: 14px 0 4px; font-weight: normal; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 3px 4px; vertical-align: top; }
        th { text-align: left; font-weight: normal; color: #6b7a8c; font-size: 8.5px; text-transform: uppercase; border-bottom: 1px solid #cfd6df; }
        .num { text-align: right; white-space: nowrap; }
        .code { width: 46px; color: #6b7a8c; font-size: 9px; }
        .group td { font-weight: bold; padding-top: 7px; }
        .indent { padding-left: 14px; }
        .subtotal td { border-top: 1px solid #e3e7ec; font-weight: bold; }
        .total td { border-top: 2px solid #9aa6b4; font-weight: bold; font-size: 11px; padding-top: 5px; }
        .muted { color: #6b7a8c; }
        .red { color: #a11807; }
        .green { color: #1d7a3e; }
        .rows td { border-bottom: 1px solid #edf0f3; }
        .summary { width: 55%; margin-left: 45%; margin-top: 12px; }
        .badge { padding: 3px 7px; font-weight: bold; font-size: 9px; }
        .tint { background: #eef6ff; padding: 8px; margin-top: 12px; }
        .big { font-size: 18px; font-weight: bold; }
    </style>
</head>
<body>
@if ($view === 'income')
    <div class="head">
        <div class="kicker">Income Statement</div>
        <h1>
            @if ($statement['range']['from'])
                {{ $date($statement['range']['from']) }} to {{ $date($statement['range']['to']) }}
            @else
                Everything to {{ $date($statement['range']['to'] ?? now()->toDateString()) }}
            @endif
        </h1>
        <div class="company">{{ $company }}</div>
    </div>

    @foreach (['revenue' => ['Revenue', 'Total revenue', 'Nothing billed in this period.'], 'expenses' => ['Expenses', 'Total expenses', 'Nothing spent in this period.']] as $key => [$title, $totalLabel, $empty])
        <h2>{{ $title }}</h2>
        <table>
            @forelse ($statement[$key]['groups'] as $group)
                <tr class="group"><td colspan="3">{{ $group['group'] }}</td></tr>
                @foreach ($group['accounts'] as $account)
                    <tr>
                        <td class="code indent">{{ $account['code'] }}</td>
                        <td>{{ $account['name'] }}</td>
                        <td class="num">{{ $peso($account['balance_cents']) }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal"><td colspan="2" class="indent">{{ $group['group'] }} total</td><td class="num">{{ $peso($group['total_cents']) }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">{{ $empty }}</td></tr>
            @endforelse
            <tr class="total"><td colspan="2">{{ $totalLabel }}</td><td class="num">{{ $peso($statement[$key]['total_cents']) }}</td></tr>
        </table>
    @endforeach

    <table class="summary">
        @if ($statement['cost_of_services_cents'] !== null)
            <tr><td class="muted">Cost of services</td><td class="num">{{ $peso($statement['cost_of_services_cents']) }}</td></tr>
            <tr>
                <td class="strong"><b>Gross profit</b></td>
                <td class="num"><b>{{ $peso($statement['gross_profit_cents'] ?? 0) }}</b>
                    @if ($statement['gross_margin_pct'] !== null) <span class="muted">{{ $statement['gross_margin_pct'] }}%</span> @endif
                </td>
            </tr>
            <tr><td class="muted">Operating and other expenses</td><td class="num">{{ $peso($statement['expenses']['total_cents'] - $statement['cost_of_services_cents']) }}</td></tr>
        @else
            <tr><td class="muted">Cost of services</td><td class="num muted">not grouped in the chart</td></tr>
        @endif
        <tr class="total">
            <td>Net income</td>
            <td class="num {{ $statement['net_income_cents'] < 0 ? 'red' : '' }}">{{ $peso($statement['net_income_cents']) }}
                @if ($statement['net_margin_pct'] !== null) <span class="muted" style="font-weight: normal;">{{ $statement['net_margin_pct'] }}%</span> @endif
            </td>
        </tr>
    </table>
@elseif ($view === 'sheet')
    <div class="head">
        <table>
            <tr>
                <td style="padding: 0;">
                    <div class="kicker">Balance Sheet</div>
                    <h1>As of {{ $date($sheet['as_of']) }}</h1>
                    <div class="company">{{ $company }}</div>
                </td>
                <td class="num" style="padding: 0;">
                    @if ($sheet['balanced'])
                        <span class="badge green">Balanced</span>
                    @else
                        <span class="badge red">Out by {{ $peso($sheet['difference_cents']) }}</span>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    @foreach (['assets' => ['Assets', 'Total assets', 'Nothing held.'], 'liabilities' => ['Liabilities', 'Total liabilities', 'Nothing owed.'], 'equity' => ['Equity', 'Total equity', '']] as $key => [$title, $totalLabel, $empty])
        <h2>{{ $title }}</h2>
        <table>
            @forelse ($sheet[$key]['groups'] as $group)
                <tr class="group"><td colspan="3">{{ $group['group'] }}</td></tr>
                @foreach ($group['accounts'] as $account)
                    <tr>
                        <td class="code indent">{{ $account['code'] }}</td>
                        <td>{{ $account['name'] }}</td>
                        <td class="num">{{ $peso($account['balance_cents']) }}</td>
                    </tr>
                @endforeach
            @empty
                @if ($empty !== '')
                    <tr><td colspan="3" class="muted">{{ $empty }}</td></tr>
                @endif
            @endforelse
            @if ($key === 'equity')
                <tr>
                    <td colspan="2" style="padding-top: 7px;">Earnings not yet closed
                        <div class="muted" style="font-size: 8.5px;">Everything earned and spent up to this date. No period close has swept it into retained earnings.</div>
                    </td>
                    <td class="num" style="padding-top: 7px;">{{ $peso($sheet['equity']['earnings_not_closed_cents']) }}</td>
                </tr>
            @endif
            <tr class="{{ $key === 'assets' ? 'total' : 'subtotal' }}"><td colspan="2">{{ $totalLabel }}</td><td class="num">{{ $peso($sheet[$key]['total_cents']) }}</td></tr>
        </table>
    @endforeach

    <table style="margin-top: 10px;">
        <tr class="total"><td>Liabilities and equity</td><td class="num">{{ $peso($sheet['liabilities_and_equity_cents']) }}</td></tr>
    </table>
@elseif ($view === 'aging')
    <div class="head">
        <div class="kicker">Aging Report</div>
        <h1>{{ $direction->value === 'receivable' ? 'Owed to us' : 'Owed by us' }} · as of {{ $date(now()->toDateString()) }}</h1>
        <div class="company">{{ $company }}</div>
    </div>

    <table style="margin-top: 10px; table-layout: fixed;">
        <tr>
            @foreach (['current' => 'Not yet due', '1_30' => '1–30 days', '31_60' => '31–60 days', '61_90' => '61–90 days', 'over_90' => 'Over 90 days'] as $bucket => $label)
                <td style="border: 1px solid #e3e7ec; padding: 7px;">
                    <div class="muted" style="font-size: 8px; text-transform: uppercase;">{{ $label }}</div>
                    <div style="font-size: 12px; font-weight: bold; margin-top: 3px;" class="{{ $bucket !== 'current' && $report['buckets'][$bucket] > 0 ? 'red' : '' }}">{{ $peso($report['buckets'][$bucket]) }}</div>
                </td>
            @endforeach
        </tr>
    </table>

    <h2>By {{ $direction->value === 'receivable' ? 'customer' : 'supplier' }}</h2>
    <table class="rows">
        <tr>
            <th>{{ $direction->value === 'receivable' ? 'Customer' : 'Supplier' }}</th>
            <th class="num">Not due</th><th class="num">1–30</th><th class="num">31–60</th><th class="num">61–90</th><th class="num">90+</th><th class="num">Total</th>
        </tr>
        @forelse ($report['by_counterparty'] as $row)
            <tr>
                <td><b>{{ $row['counterparty'] }}</b></td>
                @foreach (['current', '1_30', '31_60', '61_90', 'over_90'] as $bucket)
                    <td class="num {{ $bucket === 'over_90' && $row[$bucket] ? 'red' : '' }}">{{ $row[$bucket] ? $peso($row[$bucket]) : '—' }}</td>
                @endforeach
                <td class="num"><b>{{ $peso($row['total_cents']) }}</b></td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Nothing outstanding. Every document in this direction has been settled.</td></tr>
        @endforelse
        <tr class="total"><td colspan="6">Total outstanding</td><td class="num">{{ $peso($report['total_cents']) }}</td></tr>
    </table>
@else
    <div class="head">
        <table>
            <tr>
                @if ($logo)
                    <td style="padding: 0 10px 0 0; width: 52px;"><img src="{{ $logo }}" style="width: 48px; height: 48px;" alt=""></td>
                @endif
                <td style="padding: 0;">
                    <h1 style="margin: 0;">{{ $statement['issuer']['name'] ?? $company }}</h1>
                    @if ($statement['issuer']['address'] ?? null)<div class="company">{{ $statement['issuer']['address'] }}</div>@endif
                    <div class="company">
                        {{ $statement['issuer']['contact_phone'] ?? '' }}
                        @if (($statement['issuer']['contact_phone'] ?? null) && ($statement['issuer']['contact_email'] ?? null)) · @endif
                        {{ $statement['issuer']['contact_email'] ?? '' }}
                    </div>
                    @if ($statement['issuer']['tin'] ?? null)<div class="company">TIN {{ $statement['issuer']['tin'] }}</div>@endif
                </td>
                <td class="num" style="padding: 0;">
                    <div class="kicker">{{ $statement['title'] }}</div>
                    <div style="font-size: 12px; font-weight: bold; margin-top: 3px;">
                        @if ($statement['range']['from'])
                            {{ $date($statement['range']['from']) }} – {{ $date($statement['range']['to']) }}
                        @else
                            To {{ $date($statement['range']['to']) }}
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table style="margin-top: 8px;">
        <tr>
            <td style="width: 55%; padding-left: 0;">
                <h2 style="margin-top: 4px;">Account</h2>
                <div style="font-size: 12px; font-weight: bold;">{{ $statement['account']['name'] }}</div>
                @if ($statement['account']['address'] ?? null)<div class="muted">{{ $statement['account']['address'] }}</div>@endif
                @if ($statement['account']['contact'] ?? null)<div class="muted">{{ $statement['account']['contact'] }}</div>@endif
                @if ($statement['account']['tin'] ?? null)<div class="muted">TIN {{ $statement['account']['tin'] }}</div>@endif
            </td>
            <td>
                <table>
                    <tr><td class="muted">Opening balance</td><td class="num">{{ $peso($statement['opening_balance_cents']) }}</td></tr>
                    <tr><td class="muted">Charges</td><td class="num">{{ $peso($statement['charges_cents']) }}</td></tr>
                    <tr><td class="muted">Payments and credits</td><td class="num">{{ $peso($statement['credits_cents']) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="rows" style="margin-top: 10px;">
        <tr><th>Date</th><th>Reference</th><th>Detail</th><th class="num">Charge</th><th class="num">Credit</th><th class="num">Balance</th></tr>
        @if ($statement['opening_balance_cents'] !== 0)
            <tr><td colspan="5" class="muted">Balance brought forward</td><td class="num"><b>{{ $peso($statement['opening_balance_cents']) }}</b></td></tr>
        @endif
        @forelse ($statement['lines'] as $line)
            <tr>
                <td style="white-space: nowrap;">{{ $date($line['date']) }}</td>
                <td><b>{{ $line['reference'] }}</b></td>
                <td class="muted">{{ $line['detail'] }}@if ($line['due_at'] ?? null)<br>due {{ $date($line['due_at']) }}@endif</td>
                <td class="num">{{ $line['charge_cents'] ? $peso($line['charge_cents']) : '—' }}</td>
                <td class="num">{{ $line['credit_cents'] ? $peso($line['credit_cents']) : '—' }}</td>
                <td class="num"><b>{{ $peso($line['balance_cents']) }}</b></td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted" style="text-align: center; padding: 12px;">Nothing on this account in this period.</td></tr>
        @endforelse
    </table>

    <table style="margin-top: 12px; border-top: 2px solid #9aa6b4;">
        <tr>
            <td style="padding-top: 8px;">
                <h2 style="margin-top: 0;">{{ $statement['balance_label'] }}</h2>
                <div class="muted">{{ $statement['closing_in_words'] }}</div>
            </td>
            <td class="num big" style="padding-top: 8px;">{{ $peso($statement['closing_balance_cents']) }}</td>
        </tr>
    </table>

    <div class="tint">
        <h2 style="margin-top: 0;">Aging of the balance</h2>
        <table>
            <tr>
                @foreach (['current' => 'Not yet due', 'days_1_30' => '1–30 days', 'days_31_60' => '31–60 days', 'days_61_90' => '61–90 days', 'over_90' => 'Over 90 days'] as $bucket => $label)
                    <td>
                        <div class="muted">{{ $label }}</div>
                        <div class="{{ $bucket === 'over_90' && $statement['aging'][$bucket] > 0 ? 'red' : '' }}"><b>{{ $peso($statement['aging'][$bucket]) }}</b></div>
                    </td>
                @endforeach
            </tr>
        </table>
    </div>
@endif
</body>
</html>
