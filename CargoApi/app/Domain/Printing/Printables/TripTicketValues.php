<?php

declare(strict_types=1);

namespace App\Domain\Printing\Printables;

use App\Domain\Printing\Support\Format;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Services\TripTicketService;
use Illuminate\Support\Carbon;

final class TripTicketValues
{
    public const ANSWERS = ['yes' => 'YES', 'no' => 'NO', 'na' => 'N/A'];

    public function __construct(private readonly TripTicketService $tickets) {}

    /** @return array<string, string> */
    public static function fields(): array
    {
        $fields = [
            ...Format::companyFields(),
            'trip.reference' => 'Trip ticket number',
            'trip.dispatch_date' => 'Dispatch date',
            'trip.dispatch_day' => 'Dispatch date with weekday',
            'trip.start_off_at' => 'Start-off date and time',
            'trip.origin' => 'Origin',
            'trip.destination' => 'Destination',
            'trip.route' => 'Route (origin → destination)',
            'trip.cargo' => 'Cargo details',
            'trip.weight' => 'Weight and pieces',
            'crew.driver' => 'Driver',
            'crew.licence_no' => 'Driver licence number',
            'crew.helper_1' => 'Helper 1',
            'crew.helper_2' => 'Helper 2',
            'crew.others_label' => 'Partner / other helpers heading (blank when none)',
            'crew.others' => 'Partner or other helpers (blank when none)',
            'vehicle.model' => 'Truck model',
            'vehicle.plate' => 'Plate / body number',
            'vehicle.type' => 'Truck type',
            'customer.name' => 'Client name',
            'customer.contact' => 'Client contact number',
            'allowance.amount' => 'Allowance amount',
            'toll.amount' => 'Toll amount',
            'toll.purpose' => 'Toll purpose (route)',
            'particulars.total' => 'Allowance and toll total',
            'checklist.remarks' => 'Checklist remarks',
            'checklist.answered' => 'Who answered the checklist in the app, and when',
            'today' => 'Date printed',
        ];

        foreach (TripTicketService::CHECKLIST as $items) {
            foreach ($items as $key => $label) {
                $fields['check.'.$key] = 'Answer boxes: '.$label;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array<string, string>
     */
    public static function values(array $t): array
    {
        $trip = $t['trip'];
        $crew = $t['crew'];
        $zone = Trip::REFERENCE_TIMEZONE;
        $date = static fn (?string $v, string $format = 'F j, Y'): string => $v ? Carbon::parse($v)->format($format) : '';
        $stamp = static fn (?string $v): string => $v ? Carbon::parse($v)->timezone($zone)->format('M j, Y, g:i A') : '';
        $amount = static fn (?int $cents): string => $cents ? number_format($cents / 100, 2) : '';
        $others = trim(($crew['partner'] ?? '').(($crew['partner'] ?? null) && $crew['more_helpers'] ? ' · ' : '').implode(', ', $crew['more_helpers'] ?? []));
        $total = ($t['allowance_cents'] ?? 0) + ($t['toll_cents'] ?? 0);

        $values = [
            ...Format::companyValues(),
            'trip.reference' => (string) $trip['reference'],
            'trip.dispatch_date' => $date($trip['dispatch_date']),
            'trip.dispatch_day' => $date($trip['dispatch_date'], 'l, F j, Y'),
            'trip.start_off_at' => $stamp($trip['start_off_at']),
            'trip.origin' => (string) ($trip['origin'] ?? ''),
            'trip.destination' => (string) ($trip['destination'] ?? ''),
            'trip.route' => implode(' → ', array_filter([$trip['origin'], $trip['destination']])),
            'trip.cargo' => (string) ($trip['cargo'] ?? ''),
            'trip.weight' => implode(' · ', array_filter([
                $trip['weight_kg'] ? number_format((float) $trip['weight_kg']).' kg' : null,
                $trip['pieces'] ? number_format((float) $trip['pieces']).' pcs' : null,
            ])),
            'crew.driver' => (string) ($crew['driver'] ?? ''),
            'crew.licence_no' => (string) ($crew['licence_no'] ?? ''),
            'crew.helper_1' => (string) ($crew['helper_1'] ?? ''),
            'crew.helper_2' => (string) ($crew['helper_2'] ?? ''),
            'crew.others_label' => $others === '' ? '' : (($crew['partner'] ?? null) ? 'Partner:' : 'Other helpers:'),
            'crew.others' => $others,
            'vehicle.model' => (string) ($t['vehicle']['model'] ?? ''),
            'vehicle.plate' => (string) ($t['vehicle']['plate'] ?? ''),
            'vehicle.type' => (string) ($t['vehicle']['type'] ?? ''),
            'customer.name' => (string) ($t['customer']['name'] ?? ''),
            'customer.contact' => (string) ($t['customer']['contact'] ?? ''),
            'allowance.amount' => $amount($t['allowance_cents'] ?? null),
            'toll.amount' => $amount($t['toll_cents'] ?? null),
            'toll.purpose' => ($t['toll_cents'] ?? null) ? $trip['origin'].' – '.$trip['destination'] : '',
            'particulars.total' => $amount($total ?: null),
            'checklist.remarks' => (string) ($t['checked']['remarks'] ?? ''),
            'checklist.answered' => ($t['checked']['at'] ?? null)
                ? 'Checklist answered in the app'.(($t['checked']['by'] ?? null) ? ' by '.$t['checked']['by'] : '').' on '.$stamp($t['checked']['at']).'.'
                : '',
        ];

        foreach ($t['checklist'] as $group) {
            foreach ($group['items'] as $item) {
                $values['check.'.$item['key']] = collect(self::ANSWERS)
                    ->map(fn (string $label, string $answer) => ($item['answer'] === $answer ? '☑' : '☐').' '.$label)
                    ->implode('     ');
            }
        }

        return $values;
    }

    /** @return array<string, mixed> */
    public function sample(): array
    {
        $trip = Trip::query()->latest('scheduled_at')->first();

        if ($trip !== null) {
            return $this->tickets->build($trip);
        }

        $answers = ['yes', 'yes', 'no', 'na'];
        $i = 0;

        return [
            'issuer' => [],
            'trip' => [
                'reference' => 'CTS-'.now()->format('mdy').'-00001',
                'dispatch_date' => now()->toDateString(),
                'start_off_at' => now()->setTime(6, 30)->toIso8601String(),
                'origin' => 'Davao City',
                'destination' => 'Tagum City',
                'cargo' => 'Bagged rice, 200 sacks',
                'weight_kg' => 10000,
                'pieces' => 200,
            ],
            'crew' => [
                'driver' => 'Juan Dela Cruz',
                'licence_no' => 'D01-23-456789',
                'helper_1' => 'Pedro Santos',
                'helper_2' => 'Mario Reyes',
                'more_helpers' => [],
                'partner' => null,
            ],
            'vehicle' => ['plate' => 'NCR 4412', 'model' => 'Isuzu Elf', 'type' => 'Closed van / 6 wheelers'],
            'customer' => ['name' => 'Sample Customer Trading', 'contact' => '0917 555 0101'],
            'allowance_cents' => 150_000,
            'toll_cents' => 32_000,
            'checklist' => array_map(function (array $section) use (&$i, $answers): array {
                return [
                    ...$section,
                    'items' => array_map(function (array $item) use (&$i, $answers): array {
                        return [...$item, 'answer' => $answers[$i++ % 4]];
                    }, $section['items']),
                ];
            }, TripTicketService::checklist()),
            'checked' => ['at' => now()->toIso8601String(), 'by' => 'Juan Dela Cruz', 'remarks' => 'Sample remarks.'],
        ];
    }
}
