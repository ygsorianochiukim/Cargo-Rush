<?php

declare(strict_types=1);

namespace App\Domain\Trip\Services;

use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Pricing\Models\TruckSize;
use App\Domain\Tenancy\Services\LogoStore;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trip\Models\Trip;

class TripTicketService
{
    public const CHECKLIST = [
        'LTO driver and vehicle compliance' => [
            'licence' => "Valid driver's license with appropriate classification",
            'orcr' => 'OR/CR and current vehicle registration available',
            'permits' => 'Required permits and documents are complete and valid',
            'lights' => 'Headlights, signal lights, brake lights, horn and wipers functional',
            'mirrors_tires' => 'Side mirrors complete; tires and spare tire serviceable',
            'tools_ewd' => 'Jack, tools, Early Warning Device and valid fire extinguisher available',
        ],
        'Warehouse and loading compliance' => [
            'company_id' => 'Company ID is available and displayed as required',
            'ppe' => 'Complete PPE: safety shoes and high-visibility vest',
            'clean_truck' => 'Truck and cargo area are clean and ready for inspection',
            'tire_chocks' => 'Tire chocks available',
        ],
        'Uniform and grooming' => [
            'uniform' => 'Complete and clean company uniform is worn properly',
            'grooming' => 'Haircut and mustache neatly trimmed; beard is trimmed or clean-shaven',
            'closed_shoes' => 'Closed shoes',
        ],
        'Pre-trip and cargo safety' => [
            'fit_for_duty' => 'Fit for duty and free from alcohol or prohibited substances',
            'vehicle_checked' => 'Brakes, steering, lights, tires and fluid levels checked',
            'fuel' => 'Fuel level is sufficient for the assigned trip',
            'load_secured' => 'Cargo area inspected; load is properly arranged and secured',
            'instructions' => 'Emergency contacts, route and trip instructions are available',
            'briefing' => 'Driver and helper received route and safety briefing',
        ],
    ];

    public function __construct(
        private readonly Tenant $tenant,
        private readonly LogoStore $logos,
    ) {}

    /** @return array<string, mixed> */
    public function build(Trip $trip): array
    {
        $trip->loadMissing([
            'customer', 'driver', 'helpers', 'vehicle', 'truckCategory', 'truckSize',
            'trucker', 'truckerDriver', 'truckerVehicle', 'dispatchRecord',
        ]);

        $company = $this->tenant->company();
        $partner = $trip->trucker_id !== null;

        $vehicle = $partner ? $trip->truckerVehicle : $trip->vehicle;
        $categoryId = $vehicle?->truck_category_id ?? $trip->truck_category_id;
        $category = $categoryId === $trip->truck_category_id
            ? $trip->truckCategory
            : TruckCategory::query()->find($categoryId);
        $sizeId = $vehicle?->truck_size_id ?? $trip->truck_size_id;
        $size = $sizeId === $trip->truck_size_id
            ? $trip->truckSize
            : TruckSize::query()->find($sizeId);

        $helpers = $trip->helpers->pluck('name')->values()->all();

        return [
            'issuer' => [
                'name' => $company?->name,
                'address' => $company?->address,
                'contact_phone' => $company?->contact_phone,
                'contact_email' => $company?->contact_email,
                'logo_url' => $this->logos->url($company?->logo_path),
            ],
            'trip' => [
                'id' => $trip->getKey(),
                'reference' => $trip->reference,
                'status' => $trip->status?->value ?? $trip->status,
                'dispatch_date' => $trip->scheduled_at?->toDateString(),
                'start_off_at' => $trip->dispatchRecord?->dispatched_at?->toIso8601String(),
                'origin' => $trip->pickup_place ?: $trip->origin,
                'destination' => $trip->dropoff_place ?: $trip->destination,
                'cargo' => $trip->cargo,
                'weight_kg' => $trip->weight_kg,
                'pieces' => $trip->pieces,
                'distance_km' => $trip->distance_total_m ? round($trip->distance_total_m / 1000, 1) : null,
            ],
            'crew' => [
                'driver' => $partner ? ($trip->truckerDriver?->name ?? $trip->trucker?->name) : $trip->driver?->name,
                'licence_no' => $partner
                    ? ($trip->truckerDriver?->licence_no ?? $trip->trucker?->licence_no)
                    : $trip->driver?->licence_no,
                'helper_1' => $helpers[0] ?? null,
                'helper_2' => $helpers[1] ?? null,
                'more_helpers' => array_slice($helpers, 2),
                'partner' => $partner ? ($trip->trucker?->business_name ?: $trip->trucker?->name) : null,
            ],
            'vehicle' => [
                'plate' => $vehicle?->plate,
                'model' => $vehicle?->model,
                'type' => $this->type(
                    $category?->name,
                    $size?->name,
                    $vehicle?->wheels ? (int) $vehicle->wheels : null,
                    $vehicle?->capacity_kg ? (int) $vehicle->capacity_kg : null,
                ),
            ],
            'customer' => [
                'name' => $trip->customer?->name,
                'contact' => $trip->customer?->contact,
            ],
            'allowance_cents' => (int) LedgerEntry::query()->where('trip_id', $trip->getKey())->sum('allowance_cents') ?: null,
            'toll_cents' => $trip->toll_cents ?: null,
            'checklist' => $this->answered($trip),
            'checked' => [
                'at' => $trip->dispatch_checked_at?->toIso8601String(),
                'by' => $trip->dispatch_checked_by,
                'remarks' => $trip->dispatch_remarks,
            ],
        ];
    }

    /** @return list<array{section: string, items: list<array{key: string, label: string}>}> */
    public static function checklist(): array
    {
        return collect(self::CHECKLIST)
            ->map(fn (array $items, string $section): array => [
                'section' => $section,
                'items' => collect($items)
                    ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    public static function keys(): array
    {
        return collect(self::CHECKLIST)->flatMap(fn (array $items): array => array_keys($items))->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function answered(Trip $trip): array
    {
        $answers = (array) ($trip->dispatch_checklist ?? []);

        return array_map(fn (array $section): array => [
            ...$section,
            'items' => array_map(
                fn (array $item): array => [...$item, 'answer' => $answers[$item['key']] ?? null],
                $section['items'],
            ),
        ], self::checklist());
    }

    private function type(?string $category, ?string $size, ?int $wheels, ?int $capacityKg): ?string
    {
        $parts = array_filter([
            $category,
            $size ?: ($wheels ? "{$wheels} wheelers" : null),
            $capacityKg ? number_format($capacityKg).' kg' : null,
        ]);

        return $parts === [] ? null : implode(' / ', $parts);
    }
}
