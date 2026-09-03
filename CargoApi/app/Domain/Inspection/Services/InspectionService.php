<?php

declare(strict_types=1);

namespace App\Domain\Inspection\Services;

use App\Domain\Inspection\DTO\InspectionData;
use App\Domain\Inspection\Models\Inspection;
use App\Domain\Inspection\Repositories\InspectionRepository;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\Tone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The pre-trip check and the maintenance jobs that hang off it.
 *
 * Mobile captures both (DESIGN.md section 5.4); the back office reads them.
 */
class InspectionService
{
    /**
     * The checklist itself is a fixed contract, not a table: the mobile screen
     * and any future report have to agree on the keys, and a key that can be
     * edited at runtime would silently orphan every historical result.
     *
     * @var array<int, array<string, string>>
     */
    private const CHECKLIST = [
        ['key' => 'tires', 'label' => 'Tires', 'hint' => 'Tread depth, pressure, no cuts'],
        ['key' => 'oil', 'label' => 'Engine oil', 'hint' => 'Level between min and max'],
        ['key' => 'gears', 'label' => 'Gears and clutch', 'hint' => 'Engages cleanly, no slipping'],
        ['key' => 'brakes', 'label' => 'Brakes', 'hint' => 'Pedal firm, no pulling'],
        ['key' => 'lights', 'label' => 'Lights and signals', 'hint' => 'Head, tail, brake, indicators'],
        ['key' => 'coolant', 'label' => 'Coolant and water', 'hint' => 'Level and no visible leaks'],
        ['key' => 'documents', 'label' => 'Documents', 'hint' => 'Registration, insurance, trip ticket'],
    ];

    /**
     * Fail any of these and the unit does not roll, whatever else passed.
     * The rest are advisory.
     *
     * @var string[]
     */
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

    /**
     * Record a completed check.
     *
     * `good_to_go` is computed here from the results, never taken from the
     * client — the call is the API's to make, and a driver in a hurry should
     * not be able to post a pass over a failed brake check.
     */
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

    /**
     * Every item answered, and no critical item failed. An unanswered item is
     * not a pass — a half-filled checklist must not clear a truck.
     *
     * @param  array<string, bool>  $results
     */
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

    /**
     * @param  array<string, mixed>  $filters
     */
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
