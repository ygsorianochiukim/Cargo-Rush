@php
    $trip = $t['trip'];
    $crew = $t['crew'];
    $route = implode(' → ', array_filter([$trip['origin'], $trip['destination']]));
    $weight = implode(' · ', array_filter([
        $trip['weight_kg'] ? number_format($trip['weight_kg']).' kg' : null,
        $trip['pieces'] ? number_format($trip['pieces']).' pcs' : null,
    ]));
    $legs = [
        ['Origin / Departure', $trip['origin'], $trip['start_off_at']],
        ['First Drop-off', $trip['destination'], null],
        ['Last Drop-off', '', null],
        ['Final Return Hub', '', null],
    ];
    $expenses = ['Fuel Refill 1', 'Fuel Refill 2', 'Ticket Fees Total', 'Incidental (Parking/Scales)'];
    $others = trim(($crew['partner'] ?? '').($crew['partner'] && $crew['more_helpers'] ? ' · ' : '').implode(', ', $crew['more_helpers']));
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Trip Ticket {{ $trip['reference'] }}</title>
    <style>
        @page { margin: 8mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; line-height: 1.3; color: #111; }
        .sheet { padding: 2mm 3mm; }
        .page-two { page-break-before: always; }
        table { border-collapse: collapse; width: 100%; }
        .grid { table-layout: fixed; }
        .grid td, .grid th { border: 1px solid #333; padding: 4px 6px; vertical-align: middle; word-wrap: break-word; }
        .grid th { background: #dbe5f1; font-weight: bold; text-align: center; }
        .label { font-weight: bold; white-space: nowrap; }
        .value { text-align: center; text-transform: uppercase; }
        .heading { margin: 9px 0 3px; font-weight: bold; text-transform: uppercase; font-size: 10.5px; }
        .row td { height: 20px; }
        .band { background: #1f3b57; color: #fff; font-weight: bold; text-transform: uppercase; padding: 3px 6px; font-size: 9.5px; }
        .band-soft { background: #2e6a8e; color: #fff; font-weight: bold; text-transform: uppercase; }
        .dark th { background: #1f3b57; color: #fff; }
        .box { display: inline-block; width: 9px; height: 9px; border: 1px solid #333; margin-right: 3px; font-size: 8px; line-height: 9px; text-align: center; vertical-align: -1px; }
        .options { border: 1px solid #333; border-top: 0; padding: 5px 8px; }
        .options span.opt { margin-right: 26px; }
        .sign { border-top: 1px solid #333; padding-top: 2px; text-align: center; font-size: 9px; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .compact { font-size: 8.8px; line-height: 1.2; }
        .compact .grid td, .compact .grid th { padding: 2px 6px; }
        .compact .row td { height: 16px; }
        .compact .band { padding: 2px 6px; font-size: 8.8px; }
        .warning { color: #b3261e; font-weight: bold; text-transform: uppercase; text-align: center; font-size: 8.5px; letter-spacing: .02em; }
        .muted { color: #555; font-style: italic; font-size: 8px; }
        .plain td { border: 0; padding: 0; vertical-align: top; }
        .answers td { width: 33%; text-align: center; white-space: nowrap; vertical-align: middle; line-height: 1; padding: 0; }
        .compact .grid td.check { padding-top: 1.5px; padding-bottom: 1.5px; }
    </style>
</head>
<body>
<div class="sheet">
    <table class="plain">
        <tr>
            @if ($logo)
                <td style="width: 62px;"><img src="{{ $logo }}" style="width: 54px; height: 54px;"></td>
            @endif
            <td>
                <div style="font-size: 14px; font-weight: bold; text-transform: uppercase; letter-spacing: .03em;">{{ $t['issuer']['name'] }}</div>
                @if ($t['issuer']['address'])
                    <div>{{ $t['issuer']['address'] }}</div>
                @endif
                <div>
                    @if ($t['issuer']['contact_email']) Email: {{ $t['issuer']['contact_email'] }} @endif
                    @if ($t['issuer']['contact_phone']) · Tel: {{ $t['issuer']['contact_phone'] }} @endif
                </div>
            </td>
        </tr>
    </table>

    <p class="center bold" style="margin: 10px 0 4px; font-size: 12px; text-transform: uppercase; letter-spacing: .03em;">Official Trip Ticket</p>

    <table class="grid">
        <colgroup><col style="width: 17%"><col style="width: 33%"><col style="width: 17%"><col style="width: 33%"></colgroup>
        <tr>
            <td class="label">Trip Ticket No:</td><td class="value">{{ $trip['reference'] }}</td>
            <td class="label">Dispatch Date:</td><td class="value">{{ $date($trip['dispatch_date']) }}</td>
        </tr>
        <tr>
            <td class="label">Start-off Date:</td><td class="value" colspan="3">{{ $stamp($trip['start_off_at']) }}</td>
        </tr>
        <tr>
            <td class="label">Driver:</td><td class="value">{{ $crew['driver'] }}</td>
            <td class="label">License No.:</td><td class="value">{{ $crew['licence_no'] }}</td>
        </tr>
        <tr>
            <td class="label">Helper 1:</td><td class="value">{{ $crew['helper_1'] }}</td>
            <td class="label">Helper 2:</td><td class="value">{{ $crew['helper_2'] }}</td>
        </tr>
        @if ($others !== '')
            <tr>
                <td class="label">{{ $crew['partner'] ? 'Partner:' : 'Other helpers:' }}</td>
                <td class="value" colspan="3">{{ $others }}</td>
            </tr>
        @endif
    </table>

    <p class="heading">Vehicle &amp; Cargo Manifest</p>
    <table class="grid">
        <colgroup><col style="width: 17%"><col style="width: 33%"><col style="width: 17%"><col style="width: 33%"></colgroup>
        <tr>
            <td class="label">Truck Model:</td><td class="value">{{ $t['vehicle']['model'] }}</td>
            <td class="label">Plate / Body No.:</td><td class="value">{{ $t['vehicle']['plate'] }}</td>
        </tr>
        <tr>
            <td class="label">Client Name:</td><td class="value">{{ $t['customer']['name'] }}</td>
            <td class="label">Waybill / DR No.:</td><td></td>
        </tr>
        <tr>
            <td class="label">Cargo Details:</td><td class="value">{{ $trip['cargo'] }}</td>
            <td class="label">Weight / Volume:</td><td class="value">{{ $weight }}</td>
        </tr>
    </table>

    <p class="heading">Route, Mileage &amp; Time Log</p>
    <table class="grid">
        <colgroup><col style="width: 26%"><col style="width: 30%"><col style="width: 22%"><col style="width: 22%"></colgroup>
        <tr><th>Leg Segment / Destination</th><th>Location / Hub Landmark</th><th>Actual Date &amp; Time</th><th>Odometer (KM)</th></tr>
        @foreach ($legs as [$label, $place, $at])
            <tr class="row">
                <td class="label">{{ $label }}</td><td class="value">{{ $place }}</td><td class="value">{{ $stamp($at) }}</td><td></td>
            </tr>
        @endforeach
        <tr class="row"><td colspan="3" class="center bold">TOTAL</td><td></td></tr>
    </table>

    <p class="heading">Trip Expenses &amp; Fuel Tracking</p>
    <table class="grid">
        <colgroup><col style="width: 28%"><col style="width: 20%"><col style="width: 17%"><col style="width: 15%"><col style="width: 20%"></colgroup>
        <tr><th>Expense Item</th><th>Receipt / PO Number</th><th>Price Per Liter</th><th>Liters</th><th>Total Amount</th></tr>
        @foreach ($expenses as $item)
            <tr class="row"><td class="bold">{{ $item }}</td><td></td><td></td><td></td><td></td></tr>
        @endforeach
        <tr class="row"><td colspan="4" class="center bold">TOTAL</td><td></td></tr>
    </table>

    <table class="grid" style="margin-top: 9px;">
        <tr>
            <td style="width: 50%; padding: 8px; vertical-align: top;">
                <p class="center bold" style="margin: 0; font-size: 10px; text-transform: uppercase;">Certification</p>
                <p class="center" style="margin: 3px 0 0; font-size: 9px;">I hereby certify that all information and entries in this Official Trip Ticket are true and correct.</p>
                <p class="sign" style="margin-top: 34px;">Driver or Helper Signature Over Printed Name</p>
                <p style="margin: 8px 0 0; font-size: 9px;">Date &amp; Time: ______________________</p>
            </td>
            <td style="width: 50%; padding: 8px; vertical-align: top;">
                <p class="center bold" style="margin: 0; font-size: 10px; text-transform: uppercase;">Received By:</p>
                <p class="sign" style="margin-top: 52px;">Operations or Admin Signature Over Printed Name</p>
                <p style="margin: 8px 0 0; font-size: 9px;">Date &amp; Time: ______________________</p>
            </td>
        </tr>
    </table>
</div>

<div class="sheet compact page-two">
    <div class="center">
        @if ($logo)
            <img src="{{ $logo }}" style="width: 34px; height: 34px;">
        @endif
        <div style="margin-top: 2px; font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: .03em;">{{ $t['issuer']['name'] }}</div>
        <div style="font-size: 9.5px; font-weight: bold; text-transform: uppercase;">Allowance Disbursement &amp; Safety, LTO and Warehouse Compliance Checklist</div>
    </div>

    <table class="grid" style="margin-top: 8px;">
        <colgroup><col style="width: 18%"><col style="width: 32%"><col style="width: 14%"><col style="width: 36%"></colgroup>
        <tr>
            <td class="label">Request ID:</td><td class="value">{{ $trip['reference'] }}</td>
            <td class="label">Date:</td><td class="value">{{ $date($trip['dispatch_date'], 'l, F j, Y') }}</td>
        </tr>
        <tr>
            <td class="label">Driver's Name:</td><td class="value">{{ $crew['driver'] }}</td>
            <td class="label">Plate No.:</td><td class="value">{{ $t['vehicle']['plate'] }}</td>
        </tr>
        <tr>
            <td class="label">Helper 1:</td><td class="value">{{ $crew['helper_1'] }}</td>
            <td class="label">Helper 2:</td><td class="value">{{ $crew['helper_2'] }}</td>
        </tr>
        <tr>
            <td class="label">Client Name:</td><td class="value">{{ $t['customer']['name'] }}</td>
            <td class="label">Type:</td><td class="value">{{ $t['vehicle']['type'] }}</td>
        </tr>
        <tr>
            <td class="label">Client Contact No.:</td><td class="value">{{ $t['customer']['contact'] }}</td>
            <td class="label">Route:</td><td class="value">{{ $route }}</td>
        </tr>
    </table>

    <div class="band" style="margin-top: 8px;">Request Type</div>
    <div class="options">
        @foreach (['Fuel', 'Bank Transfer', 'Allowance', 'Other'] as $kind)
            <span class="opt"><span class="box"></span>{{ $kind }}</span>
        @endforeach
    </div>

    <table class="grid dark">
        <colgroup><col style="width: 30%"><col style="width: 45%"><col style="width: 25%"></colgroup>
        <tr><th>Itemized Particulars</th><th>Purpose</th><th>Amount</th></tr>
        <tr class="row">
            <td class="label">Allowance</td><td class="value">{{ $trip['destination'] }}</td><td class="right">{{ $amount($t['allowance_cents']) }}</td>
        </tr>
        <tr class="row">
            <td class="label">Toll</td>
            <td class="value">{{ $t['toll_cents'] ? $trip['origin'].' – '.$trip['destination'] : '' }}</td>
            <td class="right">{{ $amount($t['toll_cents']) }}</td>
        </tr>
        <tr class="row">
            <td colspan="2" class="right bold">TOTAL</td>
            <td class="right bold">{{ $amount(($t['allowance_cents'] ?? 0) + ($t['toll_cents'] ?? 0)) }}</td>
        </tr>
    </table>

    <div class="band" style="margin-top: 8px;">Release Method</div>
    <div class="options">
        @foreach (['Cash', 'Bank Transfer', 'Fuel Card', 'Cheque'] as $kind)
            <span class="opt"><span class="box"></span>{{ $kind }}</span>
        @endforeach
    </div>

    <div class="band center" style="margin-top: 8px;">Safety, LTO and Warehouse Compliance Checklist</div>
    <div class="center" style="border: 1px solid #333; border-top: 0; padding: 1px 0; font-size: 8.5px; font-style: italic;">Check one box for every item.</div>
    <table class="grid">
        <colgroup><col style="width: 70%"><col style="width: 30%"></colgroup>
        @foreach ($t['checklist'] as $group)
            <tr><td colspan="2" class="band-soft">{{ $group['section'] }}</td></tr>
            @foreach ($group['items'] as $item)
                <tr>
                    <td class="check">{{ $item['label'] }}</td>
                    <td style="padding: 0 4px;">
                        <table class="plain answers">
                            <tr>
                                @foreach (['yes' => 'YES', 'no' => 'NO', 'na' => 'N/A'] as $answer => $label)
                                    <td><span class="box">{{ $item['answer'] === $answer ? '✓' : '' }}</span>{{ $label }}</td>
                                @endforeach
                            </tr>
                        </table>
                    </td>
                </tr>
            @endforeach
        @endforeach
        <tr>
            <td colspan="2" style="height: 34px; vertical-align: top;">
                <span class="label">REMARKS:</span>
                @if ($t['checked']['remarks'])
                    <span> {{ $t['checked']['remarks'] }}</span>
                @endif
                @if ($t['checked']['at'])
                    <div class="muted" style="margin-top: 2px;">
                        Checklist answered in the app{{ $t['checked']['by'] ? ' by '.$t['checked']['by'] : '' }} on {{ $stamp($t['checked']['at']) }}.
                    </div>
                @endif
            </td>
        </tr>
    </table>

    <table class="plain" style="margin-top: 10px;">
        <tr>
            @foreach ([['Checked by', 'Operations Supervisor'], ['Prepared by', 'Admin Manager'], ['Approved by', 'General Manager'], ['Disbursed by', 'Finance / Disbursement Manager']] as [$role, $title])
                <td style="width: 25%; padding: 0 6px;">
                    <div style="font-size: 9px;">{{ $role }}:</div>
                    <div class="sign" style="margin-top: 22px;">Signature Over Printed Name</div>
                    <div class="center" style="font-size: 8.5px; font-style: italic;">{{ $title }}</div>
                </td>
            @endforeach
        </tr>
    </table>

    <table class="plain" style="margin-top: 12px; font-size: 9px;">
        <tr>
            <td style="width: 50%;">Received by / Signature: ______________________________</td>
            <td style="width: 50%;">Date / Time: ______________________________</td>
        </tr>
    </table>

    <p class="warning" style="margin-top: 10px;">No funds released or units dispatched without complete approvals and compliance.</p>
</div>
</body>
</html>
