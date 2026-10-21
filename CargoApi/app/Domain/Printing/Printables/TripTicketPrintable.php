<?php

declare(strict_types=1);

namespace App\Domain\Printing\Printables;

use App\Domain\Printing\Contracts\Printable;
use App\Domain\Printing\Support\Layout as L;

class TripTicketPrintable implements Printable
{
    public const DARK = '#1f3b57';

    public const EXPENSES = ['Fuel Refill 1', 'Fuel Refill 2', 'Ticket Fees Total', 'Incidental (Parking/Scales)'];

    public function __construct(private readonly TripTicketValues $values) {}

    public function key(): string
    {
        return 'trip-ticket';
    }

    public function name(): string
    {
        return 'Trip ticket';
    }

    public function group(): string
    {
        return 'Operations';
    }

    public function description(): string
    {
        return 'Page 1 of the trip ticket: crew, manifest, route log, expenses and certification.';
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
            'legs' => [
                ['key' => 'segment', 'label' => 'Leg Segment / Destination'],
                ['key' => 'location', 'label' => 'Location / Hub Landmark', 'align' => 'center'],
                ['key' => 'time', 'label' => 'Actual Date & Time', 'align' => 'center'],
                ['key' => 'odometer', 'label' => 'Odometer (KM)', 'align' => 'center'],
            ],
            'expenses' => [
                ['key' => 'item', 'label' => 'Expense Item'],
                ['key' => 'receipt', 'label' => 'Receipt / PO Number', 'align' => 'center'],
                ['key' => 'price', 'label' => 'Price Per Liter', 'align' => 'center'],
                ['key' => 'liters', 'label' => 'Liters', 'align' => 'center'],
                ['key' => 'total', 'label' => 'Total Amount', 'align' => 'right'],
            ],
        ];
    }

    public function defaults(): array
    {
        $label = ['size' => 8.5, 'bold' => true];
        $value = ['size' => 8.5, 'upper' => true, 'align' => 'center'];
        $tables = $this->tables();

        $grid = static function (string $prefix, float $top, array $rows) use ($label, $value): array {
            $elements = [L::line($prefix.'_box_top', 'Box top', 'top', 0, $top, 190, '#333333')];

            foreach ($rows as $i => $row) {
                $y = $top + 1.5 + $i * 6.5;
                [$l1, $v1, $l2, $v2] = $row + [null, null, null, null];
                $name = str_contains((string) $l1, '{{') ? 'Partner / other helpers' : trim((string) $l1, ':');
                $elements[] = L::text($prefix.'_l'.$i, $name, 'top', 1.5, $y, 32, $l1, $label);
                $elements[] = L::text($prefix.'_v'.$i, $name.' value', 'top', 34, $y, $l2 === null ? 155 : 60, $v1, $value);

                if ($l2 !== null) {
                    $elements[] = L::text($prefix.'_l'.$i.'b', trim($l2, ':'), 'top', 96, $y, 32, $l2, $label);
                    $elements[] = L::text($prefix.'_v'.$i.'b', trim($l2, ':').' value', 'top', 128, $y, 61, (string) $v2, $value);
                }

                $elements[] = L::line($prefix.'_rule'.$i, 'Row line', 'top', 0, $top + ($i + 1) * 6.5, 190, '#333333');
            }

            return $elements;
        };

        return L::make([
            L::image('logo', 'Logo', 'top', 0, 0, 16, 16),
            L::text('company_name', 'Company name', 'top', 19, 0.5, 170, '{{company.name}}', ['size' => 12, 'bold' => true, 'upper' => true]),
            L::text('company_details', 'Company details', 'top', 19, 6.5, 170, "{{company.address}}\nEmail: {{company.email}} · Tel: {{company.phone}}", ['size' => 8]),
            L::text('title', 'Title', 'top', 0, 20, 190, 'Official Trip Ticket', ['size' => 11, 'bold' => true, 'upper' => true, 'align' => 'center']),

            ...$grid('crew', 26, [
                ['Trip Ticket No:', '{{trip.reference}}', 'Dispatch Date:', '{{trip.dispatch_date}}'],
                ['Start-off Date:', '{{trip.start_off_at}}'],
                ['Driver:', '{{crew.driver}}', 'License No.:', '{{crew.licence_no}}'],
                ['Helper 1:', '{{crew.helper_1}}', 'Helper 2:', '{{crew.helper_2}}'],
                ['{{crew.others_label}}', '{{crew.others}}'],
            ]),

            L::text('manifest_heading', 'Manifest heading', 'top', 0, 61.5, 190, 'Vehicle & Cargo Manifest', ['size' => 9.5, 'bold' => true, 'upper' => true]),
            ...$grid('manifest', 66.5, [
                ['Truck Model:', '{{vehicle.model}}', 'Plate / Body No.:', '{{vehicle.plate}}'],
                ['Client Name:', '{{customer.name}}', 'Waybill / DR No.:', ''],
                ['Cargo Details:', '{{trip.cargo}}', 'Weight / Volume:', '{{trip.weight}}'],
            ]),

            L::table('legs', 'Route, mileage and time log', 'legs', $tables['legs'], 1, ['title' => 'Route, Mileage & Time Log', 'color' => self::DARK, 'space_before' => 0]),
            L::table('expenses', 'Trip expenses and fuel', 'expenses', $tables['expenses'], 2, ['title' => 'Trip Expenses & Fuel Tracking', 'color' => self::DARK, 'space_before' => 6]),

            L::line('cert_top', 'Certification top', 'bottom', 0, 2, 190, '#333333'),
            L::text('cert_heading', 'Certification heading', 'bottom', 0, 4, 93, 'Certification', ['size' => 9.5, 'bold' => true, 'upper' => true, 'align' => 'center']),
            L::text('cert_text', 'Certification text', 'bottom', 4, 9.5, 85, 'I hereby certify that all information and entries in this Official Trip Ticket are true and correct.', ['size' => 8.5, 'align' => 'center']),
            L::line('cert_sign_line', 'Driver signature line', 'bottom', 8, 32, 77, '#333333'),
            L::text('cert_sign', 'Driver signature caption', 'bottom', 0, 33, 93, 'Driver or Helper Signature Over Printed Name', ['size' => 8, 'align' => 'center']),
            L::text('cert_date', 'Driver date line', 'bottom', 4, 41, 85, 'Date & Time: ______________________', ['size' => 8.5]),
            L::text('received_heading', 'Received by heading', 'bottom', 97, 4, 93, 'Received By:', ['size' => 9.5, 'bold' => true, 'upper' => true, 'align' => 'center']),
            L::line('received_sign_line', 'Received by signature line', 'bottom', 105, 32, 77, '#333333'),
            L::text('received_sign', 'Received by caption', 'bottom', 97, 33, 93, 'Operations or Admin Signature Over Printed Name', ['size' => 8, 'align' => 'center']),
            L::text('received_date', 'Received by date line', 'bottom', 101, 41, 85, 'Date & Time: ______________________', ['size' => 8.5]),
            L::line('cert_bottom', 'Certification bottom', 'bottom', 0, 48, 190, '#333333'),
        ], 89, 50, 10);
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
        $trip = $ticket['trip'];

        $legs = [
            ['Origin / Departure', (string) ($trip['origin'] ?? ''), $values['trip.start_off_at']],
            ['First Drop-off', (string) ($trip['destination'] ?? ''), ''],
            ['Last Drop-off', '', ''],
            ['Final Return Hub', '', ''],
        ];

        return [
            'pages' => [[
                'values' => $values,
                'tables' => [
                    'legs' => [
                        'rows' => array_map(static fn (array $l) => [
                            'segment' => $l[0],
                            'location' => mb_strtoupper($l[1]),
                            'time' => $l[2],
                            'odometer' => ' ',
                        ], $legs),
                        'foot' => [['segment' => 'TOTAL', 'location' => '', 'time' => '', 'odometer' => '']],
                    ],
                    'expenses' => [
                        'rows' => array_map(static fn (string $item) => [
                            'item' => $item,
                            'receipt' => ' ',
                            'price' => '',
                            'liters' => '',
                            'total' => '',
                        ], self::EXPENSES),
                        'foot' => [['item' => 'TOTAL', 'receipt' => '', 'price' => '', 'liters' => '', 'total' => '']],
                    ],
                ],
            ]],
        ];
    }
}
