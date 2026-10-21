<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Asset Report</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1b2430; }
        h1 { font-size: 17px; margin: 0; }
        h2 { font-size: 11px; margin: 16px 0 6px; text-transform: uppercase; letter-spacing: .04em; color: #44546a; }
        .meta { color: #6b7a8c; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-weight: normal; color: #6b7a8c; border-bottom: 1px solid #cfd6df; padding: 4px 5px; font-size: 8.5px; text-transform: uppercase; }
        td { border-bottom: 1px solid #e6eaef; padding: 4px 5px; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .strong { font-weight: bold; }
        .tiles td { border: 1px solid #e6eaef; padding: 7px 8px; width: 16.6%; }
        .tiles .label { color: #6b7a8c; font-size: 8px; text-transform: uppercase; }
        .tiles .value { font-size: 14px; font-weight: bold; margin-top: 3px; }
        .half { width: 49%; vertical-align: top; }
        .muted { color: #6b7a8c; }
        .red { color: #c0392b; }
    </style>
</head>
<body>
    <h1>Asset Report</h1>
    <div class="meta">{{ $company }} · as of {{ $asOf }} · retired and lost items included at cost</div>

    <table class="tiles" style="margin-top: 12px;">
        <tr>
            <td><div class="label">Items</div><div class="value">{{ $report['totals']['count'] }}</div></td>
            <td><div class="label">Total value</div><div class="value">{{ $peso($report['totals']['cost_cents']) }}</div></td>
            <td><div class="label">Issued</div><div class="value">{{ $report['totals']['assigned'] }}</div></td>
            <td><div class="label">In stock</div><div class="value">{{ $report['totals']['available'] }}</div></td>
            <td><div class="label">Under repair</div><div class="value">{{ $report['totals']['maintenance'] }}</div></td>
            <td><div class="label">Retired / lost</div><div class="value">{{ $report['totals']['inactive'] }}</div></td>
        </tr>
    </table>

    <h2>Stock by category</h2>
    <table>
        <tr>
            <th>Category</th><th class="num">Items</th><th class="num">Issued</th><th class="num">In stock</th>
            <th class="num">Under repair</th><th class="num">Retired / lost</th><th class="num">Value</th>
        </tr>
        @forelse ($report['by_category'] as $c)
            <tr>
                <td class="strong">{{ $c['name'] }}</td>
                <td class="num">{{ $c['count'] }}</td>
                <td class="num">{{ $c['assigned'] }}</td>
                <td class="num">{{ $c['available'] }}</td>
                <td class="num">{{ $c['maintenance'] }}</td>
                <td class="num">{{ $c['inactive'] }}</td>
                <td class="num strong">{{ $peso($c['cost_cents']) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Nothing on the register yet.</td></tr>
        @endforelse
    </table>

    <table style="margin-top: 4px;">
        <tr>
            <td class="half" style="border: 0; padding: 0 8px 0 0;">
                <h2>Age of assets</h2>
                <table>
                    <tr><th>Age</th><th class="num">Items</th><th class="num">Value</th></tr>
                    @foreach ($report['by_age'] as $a)
                        <tr><td>{{ $a['label'] }}</td><td class="num">{{ $a['count'] }}</td><td class="num">{{ $peso($a['cost_cents']) }}</td></tr>
                    @endforeach
                </table>
            </td>
            <td class="half" style="border: 0; padding: 0 0 0 8px;">
                <h2>Purchases by year</h2>
                <table>
                    <tr><th>Year</th><th class="num">Items</th><th class="num">Spent</th></tr>
                    @forelse ($report['by_year'] as $y)
                        <tr><td class="strong">{{ $y['year'] }}</td><td class="num">{{ $y['count'] }}</td><td class="num">{{ $peso($y['cost_cents']) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="muted">No purchase dates recorded.</td></tr>
                    @endforelse
                </table>
            </td>
        </tr>
    </table>

    <h2>Accountability by custodian</h2>
    <table>
        <tr><th>Custodian</th><th>Position</th><th class="num">Items</th><th class="num">Damaged</th><th>Holding since</th><th class="num">Value held</th></tr>
        @forelse ($report['by_custodian'] as $p)
            <tr>
                <td class="strong">{{ $p['name'] }}</td>
                <td class="muted">{{ $p['position'] ?? '—' }}</td>
                <td class="num">{{ $p['count'] }}</td>
                <td class="num {{ $p['damaged'] > 0 ? 'red' : '' }}">{{ $p['damaged'] }}</td>
                <td>{{ $p['held_since'] ? \Illuminate\Support\Carbon::parse($p['held_since'])->format('M j, Y') : '—' }}</td>
                <td class="num">{{ $peso($p['cost_cents']) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">Nobody holds an asset.</td></tr>
        @endforelse
    </table>

    <h2>Asset list</h2>
    <table>
        <tr><th>Tag</th><th>Item</th><th>Category</th><th>Held by</th><th>Purchased</th><th>Condition</th><th>Status</th><th class="num">Cost</th></tr>
        @forelse ($assets as $a)
            <tr>
                <td style="white-space: nowrap;">{{ $a->tag ?? '—' }}</td>
                <td class="strong">{{ $a->name }}</td>
                <td>{{ $a->category?->name ?? '—' }}</td>
                <td>{{ $a->custodian?->fullName() ?? 'In stock' }}</td>
                <td style="white-space: nowrap;">{{ $a->purchased_on?->format('M j, Y') ?? '—' }}</td>
                <td>{{ ucfirst($a->condition) }}</td>
                <td>{{ $statusLabel($a->status->value) }}</td>
                <td class="num">{{ $a->cost_cents === null ? '—' : $peso($a->cost_cents) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Nothing on the register yet.</td></tr>
        @endforelse
    </table>
</body>
</html>
