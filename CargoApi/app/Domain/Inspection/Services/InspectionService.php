<?php

declare(strict_types=1);

namespace App\Domain\Inspection\Services;

use App\Domain\Inspection\DTO\InspectionData;
use App\Domain\Inspection\Models\Inspection;
use App\Domain\Inspection\Repositories\InspectionRepository;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\TruckerDriver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InspectionService
{
    /** @var array<int, array<string, string>> */
    private const CHECKLIST = [
        ['key' => 'tires', 'label' => 'Tires', 'hint' => 'Tread depth, pressure, no cuts'],
        ['key' => 'oil', 'label' => 'Engine oil', 'hint' => 'Level between min and max'],
        ['key' => 'gears', 'label' => 'Gears and clutch', 'hint' => 'Engages cleanly, no slipping'],
        ['key' => 'brakes', 'label' => 'Brakes', 'hint' => 'Pedal firm, no pulling'],
        ['key' => 'lights', 'label' => 'Lights and signals', 'hint' => 'Head, tail, brake, indicators'],
        ['key' => 'coolant', 'label' => 'Coolant and water', 'hint' => 'Level and no visible leaks'],
        ['key' => 'documents', 'label' => 'Documents', 'hint' => 'Registration, insurance, trip ticket'],
    ];

    /** @var string[] */
    private const CRITICAL = ['tires', 'brakes', 'lights', 'documents'];

    public function __construct(
        private readonly InspectionRepository $inspections,
        private readonly NotificationService $notifications,
    ) {}

    /** @return array<int, array<string, string>> */
    public function checklist(): array
    {
        return self::CHECKLIST;
    }

    public function clearanceFor(Trip $trip): ?Inspection
    {
        $inspection = $trip->relationLoaded('latestInspection')
            ? $trip->latestInspection
            : $this->inspections->latestForTrip($trip->getKey());

        return $inspection?->good_to_go === true ? $inspection : null;
    }

    /** @return array<string, mixed> */
    public function summaryFor(Trip $trip, bool $detailed = true): array
    {
        $inspection = $trip->relationLoaded('latestInspection')
            ? $trip->latestInspection
            : $this->inspections->latestForTrip($trip->getKey());

        $passed = $inspection?->good_to_go === true;
        $show = $detailed || $passed;

        return [
            'required' => $trip->status === StatusValue::Assigned,
            'passed' => $passed,
            'inspected_at' => $inspection?->inspected_at?->format('Y-m-d\\TH:i:s\\Z'),
            'checked_by' => $show ? $inspection?->inspectorName() : null,
            'notes' => $show ? $inspection?->notes : null,
            'items' => $inspection === null || ! $show ? [] : $this->answered($inspection),
            'failures' => $show ? ($inspection?->failures() ?? []) : [],
            'total_items' => count(self::CHECKLIST),
            'passed_items' => $inspection === null
                ? 0
                : count(array_filter($inspection->results ?? [], static fn ($ok): bool => $ok === true)),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function answered(Inspection $inspection): array
    {
        $results = $inspection->results ?? [];

        return array_values(array_map(static fn (array $item): array => [
            'key' => $item['key'],
            'label' => $item['label'],
            'hint' => $item['hint'],
            'passed' => array_key_exists($item['key'], $results) ? (bool) $results[$item['key']] : null,
            'critical' => in_array($item['key'], self::CRITICAL, true),
        ], self::CHECKLIST));
    }

    /** @param array<string, bool> $results */
    public function submitForCrew(Trip $trip, TruckerDriver $crew, array $results, ?string $notes = null): Inspection
    {
        return DB::transaction(function () use ($trip, $crew, $results, $notes): Inspection {
            $goodToGo = $this->isGoodToGo($results);

            $inspection = Inspection::create([
                'trip_id' => $trip->getKey(),
                'trucker_vehicle_id' => $trip->trucker_vehicle_id,
                'trucker_driver_id' => $crew->getKey(),
                'results' => $results,
                'notes' => $notes,
                'inspected_at' => now(),
                'good_to_go' => $goodToGo,
            ]);

            $owner = $crew->trucker?->user_id;

            if (! $goodToGo && $owner !== null) {
                $this->notifications->push(
                    icon: 'incident',
                    title: 'A truck failed its pre-trip check',
                    detail: sprintf(
                        '%s checked %s for %s and it did not pass: %s',
                        $crew->name,
                        $inspection->truckerVehicle?->plate ?? 'a truck',
                        $trip->reference,
                        implode(', ', $inspection->failures()),
                    ),
                    tone: Tone::Danger,
                    userId: $owner,
                );
            }

            return $inspection;
        });
    }

    public function submit(InspectionData $data): Inspection
    {
        return DB::transaction(function () use ($data): Inspection {
            $results = $data->results ?? [];
            $goodToGo = $this->isGoodToGo($results);

            $inspection = Inspection::create([
                ...$data->persistable(),
                'inspected_at' => $data->inspected_at ?? now(),
                'good_to_go' => $goodToGo,
            ]);

            if (! $goodToGo) {
                $failed = implode(', ', $inspection->failures());
                $this->notifications->push(
                    icon: 'incident',
                    title: 'Unit failed its pre-trip check',
                    detail: ($inspection->vehicle?->plate ?? 'A unit')." did not pass: {$failed}",
                    tone: Tone::Danger,
                );
            }

            return $inspection;
        });
    }

    /** @param array<string, bool> $results */
    public function isGoodToGo(array $results): bool
    {
        foreach (self::CHECKLIST as $item) {
            if (! array_key_exists($item['key'], $results)) {
                return false;
            }
        }

        foreach (self::CRITICAL as $key) {
            if (($results[$key] ?? false) === false) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->inspections->paginate($filters, $perPage);
    }

    public function maintenanceForVehicle(string $vehicleId): Collection
    {
        return $this->inspections->maintenanceForVehicle($vehicleId);
    }

    public function latestForTrip(string $tripId): ?Inspection
    {
        return $this->inspections->latestForTrip($tripId);
    }
}
