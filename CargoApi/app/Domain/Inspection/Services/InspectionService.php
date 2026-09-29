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
     * The check that clears this run to leave, or null if there is not one.
     *
     * **Per trip, not per unit per day**, and that is the decision worth stating.
     * A pre-trip check is a check *before a trip*: the tyres a driver looked at
     * this morning are not the tyres they are about to leave on this afternoon,
     * and the whole reason the checklist exists is the departure it precedes.
     * Accepting this morning's pass for tonight's run would turn a safety check
     * into a daily formality — and the first time it mattered, the record would
     * say a unit was cleared by an inspection that happened before the fault.
     *
     * What it costs is a second checklist for a driver doing two runs out of the
     * same yard. Seven taps, against a record that says exactly which departure
     * was cleared by which look at the truck.
     */
    public function clearanceFor(Trip $trip): ?Inspection
    {
        $inspection = $trip->relationLoaded('latestInspection')
            ? $trip->latestInspection
            : $this->inspections->latestForTrip($trip->getKey());

        return $inspection?->good_to_go === true ? $inspection : null;
    }

    /**
     * Where this run's pre-trip check stands, ready for a resource to print.
     *
     * Every client shows this and none of them should be working it out: the
     * driver's queue needs to know whether to open the checklist or start the
     * run, the office board wants to see that a unit was looked over before it
     * rolled, and the customer wants to know somebody checked the truck their
     * load is on.
     *
     * `$detailed` is what separates them. Staff and the driver see the itemised
     * result, including what failed. A customer sees the itemised result *only
     * once it passed*: a held unit is the haulier's own maintenance business,
     * and "their brakes failed" is not a sentence this system should put in
     * front of a client about a load that has not moved. What the customer does
     * see in that case is that the run has not been cleared yet, which is the
     * part that concerns them.
     *
     * @return array<string, mixed>
     */
    public function summaryFor(Trip $trip, bool $detailed = true): array
    {
        $inspection = $trip->relationLoaded('latestInspection')
            ? $trip->latestInspection
            : $this->inspections->latestForTrip($trip->getKey());

        $passed = $inspection?->good_to_go === true;
        $show = $detailed || $passed;

        return [
            /**
             * Is a check needed before this run can start?
             *
             * False once it has left: a run in transit was cleared on the way
             * out, and a delivered one is history. It is what the handset reads
             * to decide whether tapping Start opens the checklist.
             */
            'required' => $trip->status === StatusValue::Assigned,
            'passed' => $passed,
            'inspected_at' => $inspection?->inspected_at?->format('Y-m-d\\TH:i:s\\Z'),
            'checked_by' => $show ? $inspection?->inspectorName() : null,
            'notes' => $show ? $inspection?->notes : null,
            /**
             * The checklist as answered — label and verdict per item.
             *
             * The labels come from here rather than from the client, so a
             * printed or emailed copy years later still reads the way it did on
             * the day. Empty until there is a check to show.
             */
            'items' => $inspection === null || ! $show ? [] : $this->answered($inspection),
            'failures' => $show ? ($inspection?->failures() ?? []) : [],
            'total_items' => count(self::CHECKLIST),
            'passed_items' => $inspection === null
                ? 0
                : count(array_filter($inspection->results ?? [], static fn ($ok): bool => $ok === true)),
        ];
    }

    /**
     * The stored results, joined back to the checklist's own labels and order.
     *
     * Driven by `CHECKLIST` rather than by the keys in the record, so an item
     * added to the checklist later shows as unanswered on an old inspection
     * instead of vanishing from it — and so the order never depends on how a
     * client happened to serialise its object.
     *
     * @return array<int, array<string, mixed>>
     */
    private function answered(Inspection $inspection): array
    {
        $results = $inspection->results ?? [];

        return array_values(array_map(static fn (array $item): array => [
            'key' => $item['key'],
            'label' => $item['label'],
            'hint' => $item['hint'],
            // Null for an item that was not on the checklist when this
            // inspection was recorded — not a fail, which would read as a fault
            // in a truck nobody had been asked about.
            'passed' => array_key_exists($item['key'], $results) ? (bool) $results[$item['key']] : null,
            'critical' => in_array($item['key'], self::CRITICAL, true),
        ], self::CHECKLIST));
    }

    /**
     * A trucker's driver checking the trucker's truck before a run.
     *
     * The same checklist and the same verdict as a Cargo Rush driver's — the
     * API decides `good_to_go`, never the handset. What differs is whose it is:
     * the truck is a `trucker_vehicles` row and the driver a `trucker_drivers`
     * row, and a failed check is the **trucker's** to sort out, so it goes to
     * the owner rather than to Cargo Rush's office, whose unit it is not.
     *
     * @param  array<string, bool>  $results
     */
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
