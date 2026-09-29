<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Inspection\Models\Inspection;
use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;

/**
 * The pre-trip check, and the departure it stands in front of.
 *
 * DESIGN.md section 5.2 has had the checklist on the handset from the start —
 * tyres, oil, gears, brakes, lights, coolant, documents — and it had no
 * consequence: a driver could skip it and leave, which made it a form rather
 * than a check. These tests are about the consequence.
 *
 * Three properties, and they are the whole feature:
 *
 *   **No pass, no departure.** Enforced in `TripService`, so it holds for
 *   whatever calls it rather than for one endpoint.
 *
 *   **The check belongs to the run.** A pass on one trip does not clear
 *   another, because a pre-trip check is a check before *a trip* — the tyres
 *   somebody looked at this morning are not the tyres they are about to leave
 *   on this afternoon.
 *
 *   **The customer can see the truck was checked**, without being shown the
 *   haulier's maintenance faults on a load that has not moved.
 */
beforeEach(function (): void {
    // Pricing is zone-only, and an unpriced run cannot go out or be billed.
    // One card at the old tariff's figures, so every run here is priced.
    zoneCard();

    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();
    $this->customer = Customer::where('name', 'Negros Fresh Mart')->firstOrFail();

    /** A confirmed run, waiting on the driver. */
    $this->waiting = fn (array $overrides = []): string => $this->actingAs($this->admin)
        ->postJson('/api/v1/trips', [
            'origin' => 'Bacolod',
            'destination' => 'Iloilo',
            'cargo' => 'Chilled produce',
            'weight_kg' => 1800,
            'customer_id' => $this->customer->id,
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->toIso8601String(),
            'status' => StatusValue::Assigned->value,
            ...$overrides,
        ])->json('data.id');

    /** The checklist, as the handset reads it. */
    $this->checklist = fn (): array => $this->actingAs($this->marco)
        ->getJson('/api/v1/inspections/checklist')
        ->assertOk()
        ->json('data');

    /**
     * Answer the checklist for a run. `$failing` names the items to fail.
     *
     * @param  string[]  $failing
     */
    $this->check = function (string $tripId, array $failing = []) {
        $results = collect(($this->checklist)())
            ->mapWithKeys(fn (array $item): array => [
                $item['key'] => ! in_array($item['key'], $failing, true),
            ])
            ->all();

        return $this->actingAs($this->marco)->postJson('/api/v1/inspections', [
            'trip_id' => $tripId,
            'vehicle_id' => $this->vehicle->id,
            'driver_id' => $this->driver->id,
            'results' => $results,
        ]);
    };
});

describe('a unit cannot leave without a check', function (): void {
    it('refuses the run and says where to do it', function (): void {
        $id = ($this->waiting)();

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])
            ->assertStatus(422)
            // Read at a yard gate on a phone: what is missing, and where. A
            // driver told "422" rings the office.
            ->assertJsonPath(
                'message',
                fn (string $message): bool => str_contains($message, 'pre-trip check')
                    && str_contains($message, 'Inspect'),
            );

        expect(Trip::findOrFail($id)->status)->toBe(StatusValue::Assigned);
    });

    it('lets the run start once the unit has passed', function (): void {
        $id = ($this->waiting)();

        ($this->check)($id)->assertCreated()->assertJsonPath('data.good_to_go', true);

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::InTransit->value);
    });

    it('holds a unit that failed, and names the fault', function (): void {
        $id = ($this->waiting)();

        // Brakes are one of the critical items — fail one of those and the
        // unit does not roll whatever else passed.
        ($this->check)($id, ['brakes'])->assertCreated()->assertJsonPath('data.good_to_go', false);

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                fn (string $message): bool => str_contains($message, 'brakes'),
            );

        expect(Trip::findOrFail($id)->status)->toBe(StatusValue::Assigned);
    });

    it('lets it go once the fault is seen to and checked again', function (): void {
        $id = ($this->waiting)();

        ($this->check)($id, ['brakes'])->assertCreated();
        // The pads are done, and the driver checks it again. What counts is
        // where the unit stands now, not the worst it has ever been.
        ($this->check)($id)->assertCreated();

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertOk();
    });

    it('will not clear a half-finished checklist', function (): void {
        $id = ($this->waiting)();

        // Four items answered out of seven. Unanswered is not a pass — a truck
        // nobody looked at is not a truck that is fine.
        $this->actingAs($this->marco)->postJson('/api/v1/inspections', [
            'trip_id' => $id,
            'vehicle_id' => $this->vehicle->id,
            'driver_id' => $this->driver->id,
            'results' => ['tires' => true, 'brakes' => true, 'lights' => true, 'oil' => true],
        ])->assertCreated()->assertJsonPath('data.good_to_go', false);

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertStatus(422);
    });

    it('does not let one run clear another', function (): void {
        $morning = ($this->waiting)();
        $afternoon = ($this->waiting)();

        ($this->check)($morning)->assertCreated();

        // The check belongs to the run it was done for. The same unit, the same
        // driver, the same day — and a departure nobody has looked the truck
        // over for.
        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$afternoon}/start", [])
            ->assertStatus(422);

        ($this->check)($afternoon)->assertCreated();

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$afternoon}/start", [])->assertOk();
    });

    it('can be switched off for an install that records checks elsewhere', function (): void {
        config()->set('cargo.inspection.required_before_start', false);

        $id = ($this->waiting)();

        // Still recorded and still shown when there is one — it simply stops
        // being a gate.
        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertOk();
    });
});

describe('what the driver is shown', function (): void {
    it('says a confirmed run still needs its check', function (): void {
        ($this->waiting)();

        $queued = $this->actingAs($this->marco)->getJson('/api/v1/trips/pending')
            ->assertOk()
            ->json('data.0.inspection');

        // What the handset reads to decide whether tapping Start opens the
        // checklist or leaves on the run.
        expect($queued['required'])->toBeTrue()
            ->and($queued['passed'])->toBeFalse()
            ->and($queued['total_items'])->toBe(7)
            ->and($queued['items'])->toBe([]);
    });

    it('carries the check on the run they are on', function (): void {
        $id = ($this->waiting)();
        ($this->check)($id)->assertCreated();
        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertOk();

        $current = $this->actingAs($this->marco)->getJson('/api/v1/trips/current')
            ->assertOk()
            ->json('data.inspection');

        // A driver stopped at a checkpoint has the answer on the phone in their
        // hand: it passed, when, and who did it.
        expect($current['passed'])->toBeTrue()
            ->and($current['checked_by'])->toBe('Marco Reyes')
            ->and($current['inspected_at'])->not->toBeNull()
            ->and($current['passed_items'])->toBe(7)
            // No longer required: the run has left, and the check is history.
            ->and($current['required'])->toBeFalse();
    });

    it('itemises the checklist with the labels it was answered under', function (): void {
        $id = ($this->waiting)();
        ($this->check)($id, ['coolant'])->assertCreated();

        $items = $this->actingAs($this->marco)->getJson('/api/v1/trips/pending')
            ->json('data.0.inspection.items');

        $coolant = collect($items)->firstWhere('key', 'coolant');
        $brakes = collect($items)->firstWhere('key', 'brakes');

        // The labels come from the API, so a copy read back years later says
        // what it said on the day — and `critical` is why a failed coolant is
        // advisory while a failed brake is not.
        expect($items)->toHaveCount(7)
            ->and($coolant['label'])->toBe('Coolant and water')
            ->and($coolant['passed'])->toBeFalse()
            ->and($coolant['critical'])->toBeFalse()
            ->and($brakes['critical'])->toBeTrue();
    });

    it('tells the office when a unit is held', function (): void {
        $id = ($this->waiting)();

        ($this->check)($id, ['tires'])->assertCreated();

        // The existing behaviour, and worth pinning beside the gate: a held
        // unit is somebody's job to fix, and a notification is how they hear.
        expect(
            NotificationItem::query()
                ->where('title', 'Unit failed its pre-trip check')
                ->exists()
        )->toBeTrue();
    });
});

describe('what the customer is shown', function (): void {
    beforeEach(function (): void {
        // A customer of this haulier, with a login of their own.
        $this->buyer = User::where('email', 'orders@negrosfresh.ph')->firstOrFail();
    });

    it('sees that the unit was checked before their load left', function (): void {
        $id = ($this->waiting)();
        ($this->check)($id)->assertCreated();
        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertOk();

        $inspection = $this->actingAs($this->buyer)->getJson("/api/v1/portal/requests/{$id}")
            ->assertOk()
            ->json('data.inspection');

        // Their load, on somebody's truck. That the truck was looked over is
        // exactly the sort of thing a customer rings to ask.
        expect($inspection['passed'])->toBeTrue()
            ->and($inspection['checked_by'])->toBe('Marco Reyes')
            ->and($inspection['items'])->toHaveCount(7)
            ->and($inspection['passed_items'])->toBe(7);
    });

    it('is not shown which part failed while the load is still in the yard', function (): void {
        $id = ($this->waiting)();
        ($this->check)($id, ['brakes'])->assertCreated();

        $inspection = $this->actingAs($this->buyer)->getJson("/api/v1/portal/requests/{$id}")
            ->assertOk()
            ->json('data.inspection');

        // Not cleared, and the customer is told that much — a pickup they are
        // waiting on has not left. Which brake failed is between the fleet and
        // its mechanic.
        expect($inspection['passed'])->toBeFalse()
            ->and($inspection['required'])->toBeTrue()
            ->and($inspection['failures'])->toBe([])
            ->and($inspection['items'])->toBe([])
            ->and($inspection['checked_by'])->toBeNull();
    });

    it('shows it on their list of deliveries too', function (): void {
        $id = ($this->waiting)();
        ($this->check)($id)->assertCreated();

        $rows = $this->actingAs($this->buyer)->getJson('/api/v1/portal/requests')
            ->assertOk()
            ->json('data');

        $row = collect($rows)->firstWhere('id', $id);

        expect($row['inspection']['passed'])->toBeTrue();
    });

    it('cannot read another carrier check by asking for the trip', function (): void {
        $id = ($this->waiting)();
        ($this->check)($id)->assertCreated();

        // A driver of the same company reading their own is fine; the point
        // here is the record itself, which stays inside the company like every
        // other row.
        expect(Inspection::query()->where('trip_id', $id)->count())->toBe(1);

        $rival = $this->makeCompany('Bay Coast Logistics');

        expect($this->asCompany($rival, fn (): int => Inspection::query()->count()))->toBe(0);
    });
});
