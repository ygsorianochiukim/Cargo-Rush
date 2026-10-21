@php
    $issuer = $doc['issuer'];
    $billTo = $doc['bill_to'];
    $document = $doc['document'];
    $totals = $doc['totals'];
    $initials = collect(preg_split('/\s+/', trim((string) $issuer['name'])))
        ->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    $statusLabel = ucfirst(str_replace('_', ' ', (string) $document['status']));
    $vatRate = ($totals['vat_rate_bp'] ?? 0) / 100;
    $whtRate = ($totals['withholding_rate_bp'] ?? 0) / 100;
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $document['number'] }}</title>
    <style>
        @page { margin: 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1b2430; }
        table { width: 100%; border-collapse: collapse; }
        .meta { color: #6b7a8c; font-size: 8px; text-transform: uppercase; letter-spacing: .05em; }
        .muted { color: #6b7a8c; }
        .num { text-align: right; white-space: nowrap; }
        .rule { border-bottom: 1px solid #dfe4ea; }
        .logo { width: 48px; height: 48px; }
        .initials { width: 48px; height: 48px; background: #dff0ff; color: #15589c; font-size: 16px; font-weight: bold; text-align: center; vertical-align: middle; }
        .title { color: #15589c; font-size: 9px; font-weight: bold; letter-spacing: .14em; text-transform: uppercase; }
        .number { font-size: 18px; font-weight: bold; margin-top: 3px; }
        .pill { display: inline-block; margin-top: 5px; padding: 2px 8px; background: #dff0ff; color: #15589c; font-size: 8.5px; font-weight: bold; }
        .lines th { text-align: left; font-weight: normal; color: #6b7a8c; font-size: 8px; text-transform: uppercase; border-bottom: 1px solid #dfe4ea; padding: 5px 6px 5px 0; }
        .lines th.num { text-align: right; }
        .lines td { border-bottom: 1px solid #eef1f4; padding: 7px 6px 7px 0; vertical-align: top; }
        .totals td { padding: 3px 0; }
        .strong { font-weight: bold; }
        .red { color: #a11807; }
        .green { color: #1e7a46; }
        .sign { border-top: 1px solid #8a96a5; padding-top: 3px; margin-top: 32px; }
    </style>
</head>
<body>
    <table class="rule">
        <tr>
            <td style="width: 58px; vertical-align: top; padding-bottom: 14px;">
                @if ($logo)
                    <img src="{{ $logo }}" class="logo" alt="">
                @else
                    <table style="width: 48px;"><tr><td class="initials">{{ $initials }}</td></tr></table>
                @endif
            </td>
            <td style="vertical-align: top; padding-bottom: 14px;">
                <div style="font-size: 15px; font-weight: bold;">{{ $issuer['name'] }}</div>
                @if ($issuer['address'])
                    <div class="muted">{{ $issuer['address'] }}</div>
                @endif
                <div class="muted">
                    {{ $issuer['contact_phone'] }}@if ($issuer['contact_phone'] && $issuer['contact_email']) · @endif{{ $issuer['contact_email'] }}
                </div>
                @if ($issuer['tin'])
                    <div class="muted" style="margin-top: 2px;">TIN {{ $issuer['tin'] }}@if ($issuer['vat_registered']) · VAT registered @endif</div>
                @endif
            </td>
            <td style="vertical-align: top; text-align: right; padding-bottom: 14px;">
                <div class="title">{{ $document['title'] }}</div>
                <div class="number">{{ $document['number'] }}</div>
                <div class="pill">{{ $statusLabel }}</div>
            </td>
        </tr>
    </table>

    <table class="rule">
        <tr>
            <td style="width: 60%; vertical-align: top; padding: 14px 0;">
                <div class="meta">Billed to</div>
                <div style="font-size: 11px; font-weight: bold; margin-top: 2px;">{{ $billTo['name'] }}</div>
                @if ($billTo['address'])
                    <div class="muted">{{ $billTo['address'] }}</div>
                @endif
                @if ($billTo['contact'])
                    <div class="muted">{{ $billTo['contact'] }}</div>
                @endif
                @if ($billTo['tin'])
                    <div class="muted" style="margin-top: 2px;">TIN {{ $billTo['tin'] }}</div>
                @endif
                @if ($billTo['vat_treatment_label'])
                    <div class="muted">{{ $billTo['vat_treatment_label'] }}</div>
                @endif
            </td>
            <td style="vertical-align: top; padding: 14px 0;">
                <table>
                    <tr><td class="meta">Issued</td><td class="num">{{ $date($document['issued_at']) }}</td></tr>
                    <tr><td class="meta">Due</td><td class="num {{ $document['overdue'] ? 'red' : '' }}">{{ $date($document['due_at']) }}</td></tr>
                    @if ($document['terms_days'] !== null)
                        <tr><td class="meta">Terms</td><td class="num">{{ $document['terms_days'] }} days</td></tr>
                    @endif
                    <tr><td class="meta">Currency</td><td class="num">{{ $document['currency'] }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="meta" style="margin: 14px 0 4px;">For the following delivery</div>
    <table class="lines">
        <tr>
            <th>Description</th><th>Reference</th><th>Delivered</th><th class="num">Weight</th><th class="num" style="padding-right: 0;">Amount</th>
        </tr>
        @foreach ($doc['lines'] as $line)
            <tr>
                <td>
                    <div style="font-weight: bold;">{{ $line['description'] }}</div>
                    @if ($line['cargo'])
                        <div class="muted">{{ $line['cargo'] }}</div>
                    @endif
                    @if ($line['plate'] || $line['driver'])
                        <div class="muted">
                            @if ($line['plate'])Unit {{ $line['plate'] }}@endif
                            @if ($line['plate'] && $line['driver']) · @endif
                            {{ $line['driver'] }}
                        </div>
                    @endif
                </td>
                <td>{{ $line['reference'] ?? '—' }}</td>
                <td style="white-space: nowrap;">{{ $date($line['delivered_at']) }}</td>
                <td class="num">{{ $line['weight_kg'] === null ? '—' : number_format((float) $line['weight_kg']).' kg' }}</td>
                <td class="num strong" style="padding-right: 0;">{{ $money($line['amount_cents']) }}</td>
            </tr>
        @endforeach
    </table>

    <table style="margin-top: 14px;">
        <tr>
            <td style="width: 55%; vertical-align: top; padding-right: 18px;">
                <div class="meta">Amount in words</div>
                <div style="font-size: 10.5px; margin-top: 2px;">{{ $totals['amount_in_words'] }}</div>
                @if ($totals['withholding_cents'] > 0)
                    <div class="muted" style="margin-top: 8px;">
                        {{ $whtRate }}% creditable withholding tax has been deducted. The balance is remitted to the BIR by the payer, and Form 2307 is expected with the payment.
                    </div>
                @endif
            </td>
            <td style="vertical-align: top;">
                <table class="totals">
                    <tr><td class="muted">Net of VAT</td><td class="num">{{ $money($totals['net_amount_cents']) }}</td></tr>
                    <tr><td class="muted">VAT @if ($totals['vat_rate_bp'])({{ $vatRate }}%)@endif</td><td class="num">{{ $money($totals['vat_cents']) }}</td></tr>
                    <tr><td class="strong" style="border-top: 1px solid #dfe4ea; padding-top: 5px;">Invoice total</td><td class="num strong" style="border-top: 1px solid #dfe4ea; padding-top: 5px;">{{ $money($totals['amount_cents']) }}</td></tr>
                    @if ($totals['withholding_cents'] > 0)
                        <tr><td class="muted">Less withholding ({{ $whtRate }}%)</td><td class="num">−{{ $money($totals['withholding_cents']) }}</td></tr>
                    @endif
                    <tr><td class="strong" style="border-top: 1px solid #dfe4ea; padding-top: 5px;">Amount payable</td><td class="num strong" style="border-top: 1px solid #dfe4ea; padding-top: 5px; font-size: 11px;">{{ $money($totals['due_cents']) }}</td></tr>
                    @if ($totals['paid_cents'] > 0)
                        <tr><td class="muted">Received</td><td class="num green">−{{ $money($totals['paid_cents']) }}</td></tr>
                        <tr><td class="strong" style="border-top: 1px solid #dfe4ea; padding-top: 5px;">Balance due</td><td class="num strong {{ $totals['balance_cents'] === 0 ? 'green' : '' }}" style="border-top: 1px solid #dfe4ea; padding-top: 5px; font-size: 11px;">{{ $money($totals['balance_cents']) }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @if (count($doc['payments']) > 0)
        <div class="meta" style="margin: 18px 0 4px;">Payments received</div>
        <table class="lines">
            <tr><th>Date</th><th>Method</th><th>Reference</th><th>Recorded by</th><th class="num" style="padding-right: 0;">Amount</th></tr>
            @foreach ($doc['payments'] as $payment)
                <tr>
                    <td>{{ $date($payment['paid_on']) }}</td>
                    <td>{{ $payment['method_label'] ?? '—' }}</td>
                    <td>{{ $payment['reference'] ?? '—' }}</td>
                    <td class="muted">{{ $payment['recorded_by'] ?? '—' }}</td>
                    <td class="num strong" style="padding-right: 0;">{{ $money($payment['amount_cents']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <table style="margin-top: 40px; page-break-inside: avoid;">
        <tr>
            <td style="width: 50%; padding-right: 20px;">
                <div class="meta">Issued by</div>
                <div class="sign muted">{{ $issuer['contact_name'] ?? $issuer['name'] }}</div>
            </td>
            <td style="width: 50%; padding-left: 20px;">
                <div class="meta">Received by</div>
                <div class="sign muted">Name, signature and date</div>
            </td>
        </tr>
    </table>

    <div class="muted" style="text-align: center; margin-top: 24px; font-size: 8px;">
        {{ $document['number'] }} · {{ $issuer['name'] }}@if ($issuer['tin']) · TIN {{ $issuer['tin'] }}@endif · This document was generated by Cargo Rush.
    </div>
</body>
</html>
