<?php

declare(strict_types=1);

namespace App\Domain\Printing\Printables;

use App\Domain\Printing\Contracts\Printable;
use App\Domain\Printing\Support\Layout as L;
use App\Domain\Trip\Services\TripTicketService;

class TripTicketChecklistPrintable implements Printable
{
    public const SOFT = '#2e6a8e';

    public const WARNING = '#b3261e';

    public function __construct(private readonly TripTicketValues $values) {}

    public function key(): string
    {
        return 'trip-ticket-checklist';
    }

    public function name(): string
    {
        return 'Trip ticket — checklist';
    }

    public function group(): string
    {
        return 'Operations';
    }

    public function description(): string
    {
        return 'Page 2 of the trip ticket: allowance disbursement and the safety, LTO and warehouse checklist.';
    }

    public function orientation(): string
    {
        return 'portrait';
    }

    public function fields(): array
    {
        return TripTicketValues::fields();
    }

    public function tables(): array
    {
        return [
            'particulars' => [
                ['key' => 'item', 'label' => 'Itemized Particulars'],
                ['key' => 'purpose', 'label' => 'Purpose', 'align' => 'center'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right'],
            ],
        ];
    }

    public function defaults(): array
    {
        $dark = TripTicketPrintable::DARK;
        $label = ['size' => 8, 'bold' => true];
        $value = ['size' => 7.5, 'upper' => true, 'align' => 'center'];
        $band = ['size' => 8, 'bold' => true, 'upper' => true, 'color' => '#ffffff'];
        $item = ['size' => 7.2, 'line_height' => 1.2];
        $pitch = 4.0;

        $info = [
            ['Request ID:', '{{trip.reference}}', 'Date:', '{{trip.dispatch_day}}'],
            ["Driver's Name:", '{{crew.driver}}', 'Plate No.:', '{{vehicle.plate}}'],
            ['Helper 1:', '{{crew.helper_1}}', 'Helper 2:', '{{crew.helper_2}}'],
            ['Client Name:', '{{customer.name}}', 'Type:', '{{vehicle.type}}'],
            ['Client Contact No.:', '{{customer.contact}}', 'Route:', '{{trip.route}}'],
        ];

        $elements = [
            L::image('logo', 'Logo', 'top', 90, 0, 10, 10),
            L::text('company_name', 'Company name', 'top', 0, 10.5, 190, '{{company.name}}', ['size' => 11, 'bold' => true, 'upper' => true, 'align' => 'center']),
            L::text('subtitle', 'Subtitle', 'top', 0, 16, 190, 'Allowance Disbursement & Safety, LTO and Warehouse Compliance Checklist', ['size' => 8.5, 'bold' => true, 'upper' => true, 'align' => 'center']),
            L::line('info_top', 'Info box top', 'top', 0, 21.5, 190, '#333333'),
        ];

        foreach ($info as $i => [$l1, $v1, $l2, $v2]) {
            $y = 22.7 + $i * 5.6;
            $elements[] = L::text('info_l'.$i, trim($l1, ':'), 'top', 1.5, $y, 32, $l1, $label);
            $elements[] = L::text('info_v'.$i, trim($l1, ':').' value', 'top', 34, $y, 58, $v1, $value);
            $elements[] = L::text('info_l'.$i.'b', trim($l2, ':'), 'top', 94, $y, 24, $l2, $label);
            $elements[] = L::text('info_v'.$i.'b', trim($l2, ':').' value', 'top', 118, $y, 71, $v2, $l2 === 'Route:' ? ['size' => 6.5] + $value : $value);
            $elements[] = L::line('info_rule'.$i, 'Info row line', 'top', 0, 21.5 + ($i + 1) * 5.6, 190, '#333333');
        }

        $elements = [
            ...$elements,
            self::filled('request_band', 'Request type band', 'top', 52, 5, $dark),
            L::text('request_heading', 'Request type heading', 'top', 2, 52.6, 120, 'Request Type', $band),
            L::text('request_options', 'Request type options', 'top', 3, 58.3, 185, '☐ Fuel          ☐ Bank Transfer          ☐ Allowance          ☐ Other', ['size' => 8]),
            L::line('request_rule', 'Request type bottom', 'top', 0, 63.5, 190, '#333333'),

            L::table('particulars', 'Itemized particulars', 'particulars', $this->tables()['particulars'], 1, ['color' => $dark, 'space_before' => 0, 'size' => 8]),

            self::filled('release_band', 'Release method band', 'bottom', 0, 5, $dark),
            L::text('release_heading', 'Release method heading', 'bottom', 2, 0.6, 120, 'Release Method', $band),
            L::text('release_options', 'Release method options', 'bottom', 3, 6.3, 185, '☐ Cash          ☐ Bank Transfer          ☐ Fuel Card          ☐ Cheque', ['size' => 8]),
            L::line('release_rule', 'Release method bottom', 'bottom', 0, 11.5, 190, '#333333'),

            self::filled('checklist_band', 'Checklist band', 'bottom', 14, 5, $dark),
            L::text('checklist_heading', 'Checklist heading', 'bottom', 0, 14.6, 190, 'Safety, LTO and Warehouse Compliance Checklist', $band + ['align' => 'center']),
            L::text('checklist_note', 'Checklist note', 'bottom', 0, 19.6, 190, 'Check one box for every item.', ['size' => 7.5, 'italic' => true, 'align' => 'center']),
        ];

        $y = 24;

        foreach (array_keys(TripTicketService::CHECKLIST) as $s => $section) {
            $elements[] = self::filled('section_band'.$s, 'Section band '.($s + 1), 'bottom', $y, 4.4, self::SOFT);
            $elements[] = L::text('section'.$s, 'Section '.($s + 1).' heading', 'bottom', 2, $y + 0.5, 186, $section, ['size' => 7.5, 'bold' => true, 'upper' => true, 'color' => '#ffffff']);
            $y += 5.2;

            foreach (TripTicketService::CHECKLIST[$section] as $key => $label) {
                $elements[] = L::text('item_'.$key, 'Checklist: '.$label, 'bottom', 2, $y, 128, $label, $item);
                $elements[] = L::text('answer_'.$key, 'Answer: '.$label, 'bottom', 132, $y, 57, '{{check.'.$key.'}}', $item);
                $elements[] = L::line('rule_'.$key, 'Row line: '.$label, 'bottom', 0, round($y + $pitch - 0.4, 1), 190, '#d0d5dc', 0.15);
                $y = round($y + $pitch, 1);
            }

            $y += 0.6;
        }

        $elements = [
            ...$elements,
            L::line('remarks_top', 'Remarks top', 'bottom', 0, $y, 190, '#333333'),
            L::text('remarks', 'Remarks', 'bottom', 1.5, $y + 1.5, 187, 'REMARKS: {{checklist.remarks}}', ['size' => 8]),
            L::text('answered', 'Answered in the app note', 'bottom', 1.5, $y + 10.5, 187, '{{checklist.answered}}', ['size' => 7, 'italic' => true, 'color' => '#555555']),
            L::line('remarks_bottom', 'Remarks bottom', 'bottom', 0, $y + 15, 190, '#333333'),
        ];

        $y += 17.5;

        foreach ([['Checked by', 'Operations Supervisor'], ['Prepared by', 'Admin Manager'], ['Approved by', 'General Manager'], ['Disbursed by', 'Finance / Disbursement Manager']] as $c => [$role, $title]) {
            $x = $c * 47.5;
            $elements[] = L::text('sign_role'.$c, $role, 'bottom', $x + 1.5, $y, 44, $role.':', ['size' => 8]);
            $elements[] = L::line('sign_line'.$c, $role.' line', 'bottom', $x + 2.5, $y + 10, 42.5, '#333333');
            $elements[] = L::text('sign_caption'.$c, $role.' caption', 'bottom', $x, $y + 10.5, 47.5, 'Signature Over Printed Name', ['size' => 7, 'align' => 'center']);
            $elements[] = L::text('sign_title'.$c, $role.' title', 'bottom', $x, $y + 13.8, 47.5, $title, ['size' => 7, 'italic' => true, 'align' => 'center']);
        }

        $y += 20;

        $elements = [
            ...$elements,
            L::text('received_by', 'Received by line', 'bottom', 0, $y, 95, 'Received by / Signature: ______________________________', ['size' => 8]),
            L::text('received_at', 'Received date line', 'bottom', 95, $y, 95, 'Date / Time: ______________________________', ['size' => 8]),
            L::text('warning', 'Warning', 'bottom', 0, $y + 6, 190, 'No funds released or units dispatched without complete approvals and compliance.', ['size' => 7.5, 'bold' => true, 'upper' => true, 'align' => 'center', 'color' => self::WARNING]),
        ];

        return L::make($elements, 66, round($y + 11, 1), 10);
    }

    public function sample(): array
    {
        return $this->data($this->values->sample());
    }

    /**
     * @param  array<string, mixed>  $ticket
     * @return array<string, mixed>
     */
    public function data(array $ticket): array
    {
        $values = TripTicketValues::values($ticket);

        return [
            'pages' => [[
                'values' => $values,
                'tables' => [
                    'particulars' => [
                        'rows' => [
                            ['item' => 'Allowance', 'purpose' => mb_strtoupper((string) ($ticket['trip']['destination'] ?? '')), 'amount' => $values['allowance.amount']],
                            ['item' => 'Toll', 'purpose' => mb_strtoupper($values['toll.purpose']), 'amount' => $values['toll.amount']],
                        ],
                        'foot' => [['item' => '', 'purpose' => 'TOTAL', 'amount' => $values['particulars.total']]],
                    ],
                ],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private static function filled(string $id, string $label, string $zone, float $y, float $h, string $color): array
    {
        return [
            'id' => $id,
            'type' => 'box',
            'label' => $label,
            'zone' => $zone,
            'x' => 0,
            'y' => $y,
            'w' => 190,
            'h' => $h,
            'color' => $color,
            'fill' => $color,
            'thickness' => 0.1,
            'hidden' => false,
            'custom' => false,
        ];
    }
}
