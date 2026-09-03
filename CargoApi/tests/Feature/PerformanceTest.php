<?php

declare(strict_types=1);

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Driver\Models\Driver;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Incident\Models\Incident;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\NavigationSeeder;

/**
 * Performance, derived from the operational record.
 *
 * The tests that matter are the ones about what *does not* count: a cancelled
 * run is not the driver failing, a trip with no promised ETA is not late, a
 * pending leave request is not time off, and an office clerk with no trips is
 * not the worst driver on the fleet.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);

    $this->admin = User::factory()->create(['role' => Role::Administrator]);

    $this->driver = Driver::create([
        'name' => 'Marco Reyes',
        'licence_no' => 'N01-23-456789',
        'licence_expiry' => '2029-01-01',
    ]);

    $this->employee = Employee::create([
        'employee_no' => 'EMP-0001',
        'first_name' => 'Marco',
        'last_name' => 'Reyes',
        'position' => 'Driver',
        'hired_on' => '2026-01-01',
        'contact' => '0917 555 0101',
        'driver_id' => $this->driver->id,
    ]);

    $this->reference = 0;

    /** A trip on Marco, with whatever status and timings the test needs. */
    $this->trip = function (array $overrides = []): Trip {
        $this->reference++;

        return Trip::create([
            'reference' => 'CR-1000'.$this->reference,
            'origin' => 'Davao City',
            'destination' => 'Tagum',
            'cargo' => 'Dry goods',
            'weight_kg' => 1000,
            'driver_id' => $this->driver->id,
            'status' => StatusValue::Delivered->value,
            'scheduled_at' => now()->subDays(3),
            'distance_total_m' => 60_000,
            'price_cents' => 400_000,
            ...$overrides,
        ]);
    };

    $this->deliver = function (Trip $trip, string $at): void {
        DeliveryLog::create([
            'trip_id' => $trip->id,
            'delivered_at' => $at,
            'status' => StatusValue::Delivered->value,
        ]);
    };

    $this->figures = fn (array $query = []) => $this->actingAs($this->admin)
        ->getJson("/api/v1/hr/performance/{$this->employee->id}?".http_build_query($query));
});

describe('one person', function (): void {
    it('counts the runs they were on', function (): void {
        ($this->trip)();
        ($this->trip)();
        ($this->trip)(['status' => StatusValue::Assigned->value]);

        $response = ($this->figures)()->assertOk();

        expect($response->json('data.drives'))->toBeTrue();
        expect($response->json('data.trips_assigned'))->toBe(3);
        expect($response->json('data.trips_completed'))->toBe(2);
        expect($response->json('data.distance_km'))->toBe(120);
        expect($response->json('data.revenue_cents'))->toBe(800_000);
    });

    it('counts the runs they rode on as helper too', function (): void {
        ($this->trip)(['driver_id' => null, 'helper_id' => $this->driver->id]);

        // Counting only the driver's seat would show every helper as a zero
        // and make the module useless for half the crew.
        expect(($this->figures)()->json('data.trips_completed'))->toBe(1);
    });

    it('does not hold a cancelled run against them', function (): void {
        ($this->trip)();
        ($this->trip)(['status' => StatusValue::Cancelled->value]);

        $response = ($this->figures)()->assertOk();

        expect($response->json('data.trips_cancelled'))->toBe(1);
        // One delivered of one completable — a customer calling off a booking
        // is not the driver failing to complete it.
        expect($response->json('data.completion_rate'))->toEqual(1.0);
    });

    it('marks a late delivery as late', function (): void {
        $late = ($this->trip)(['eta' => now()->subDays(3)->setTime(14, 0)]);
        ($this->deliver)($late, now()->subDays(3)->setTime(17, 30)->toDateTimeString());

        $response = ($this->figures)()->assertOk();

        expect($response->json('data.trips_on_time'))->toBe(0);
        expect($response->json('data.on_time_rate'))->toEqual(0.0);
    });

    it('counts an arrival inside the ETA as on time', function (): void {
        $ok = ($this->trip)(['eta' => now()->subDays(3)->setTime(18, 0)]);
        ($this->deliver)($ok, now()->subDays(3)->setTime(16, 15)->toDateTimeString());

        expect(($this->figures)()->json('data.on_time_rate'))->toEqual(1.0);
    });

    it('does not call a run late when nobody promised a time', function (): void {
        // Booked over the phone against a town name, with no ETA entered.
        // Marking it late would punish the driver for what the desk skipped.
        $trip = ($this->trip)(['eta' => null]);
        ($this->deliver)($trip, now()->subDays(2)->toDateTimeString());

        expect(($this->figures)()->json('data.trips_on_time'))->toBe(1);
    });

    it('has no rate at all when nothing was delivered', function (): void {
        ($this->trip)(['status' => StatusValue::Assigned->value]);

        $response = ($this->figures)()->assertOk();

        // Null, not 100% — a green figure over an empty month is a lie.
        expect($response->json('data.on_time_rate'))->toBeNull();
    });

    it('counts incidents they were involved in', function (): void {
        Incident::create([
            'reference' => 'IN-001',
            'kind' => 'Breakdown',
            'place' => 'Panabo',
            'occurred_at' => now()->subDays(4),
            'driver_id' => $this->driver->id,
        ]);

        expect(($this->figures)()->json('data.incidents'))->toBe(1);
    });

    it('leaves out work outside the window', function (): void {
        ($this->trip)(['scheduled_at' => now()->subMonths(6)]);

        expect(($this->figures)()->json('data.trips_assigned'))->toBe(0);

        $wide = ($this->figures)([
            'from' => now()->subYear()->toDateString(),
            'to' => now()->toDateString(),
        ]);

        expect($wide->json('data.trips_assigned'))->toBe(1);
    });

    it('counts approved time off, and only approved', function (): void {
        $leave = $this->actingAs($this->admin)->postJson('/api/v1/hr/leave', [
            'employee_id' => $this->employee->id,
            'type' => 'vacation',
            'starts_on' => now()->subDays(5)->toDateString(),
            'ends_on' => now()->subDays(3)->toDateString(),
            'reason' => 'Family trip',
        ])->json('data.id');

        // Still pending — counting it would penalise them for asking.
        expect(($this->figures)()->json('data.leave_days'))->toEqual(0.0);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/hr/leave/$leave/decision", ['decision' => 'approved']);

        expect(($this->figures)()->json('data.leave_days'))->toEqual(3.0);
    });

    it('counts approved undertime hours', function (): void {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/hr/undertime', [
            'employee_id' => $this->employee->id,
            'date' => now()->subDays(2)->toDateString(),
            'from_time' => '15:30',
            'to_time' => '17:00',
            'reason' => 'Clinic',
        ])->json('data.id');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/hr/undertime/$id/decision", ['decision' => 'approved']);

        expect(($this->figures)()->json('data.undertime_hours'))->toEqual(1.5);
    });
});

describe('somebody who does not drive', function (): void {
    it('says so rather than scoring them as a bad driver', function (): void {
        $clerk = Employee::create([
            'employee_no' => 'EMP-0002',
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'position' => 'Accounting Clerk',
            'hired_on' => '2026-01-01',
            'contact' => '0918 555 0202',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/hr/performance/{$clerk->id}")
            ->assertOk();

        expect($response->json('data.drives'))->toBeFalse();
        expect($response->json('data.trips_completed'))->toBe(0);
        expect($response->json('data.on_time_rate'))->toBeNull();
    });

    it('is kept off the leaderboard entirely', function (): void {
        Employee::create([
            'employee_no' => 'EMP-0002',
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'position' => 'Accounting Clerk',
            'hired_on' => '2026-01-01',
            'contact' => '0918 555 0202',
        ]);

        ($this->trip)();

        $board = $this->actingAs($this->admin)->getJson('/api/v1/hr/performance')->assertOk();

        expect($board->json('data.crew'))->toHaveCount(1);
        expect($board->json('data.crew.0.employee.name'))->toBe('Marco Reyes');
    });
});

describe('the leaderboard', function (): void {
    it('ranks by runs completed and totals the fleet', function (): void {
        $second = Driver::create([
            'name' => 'Jun Abad',
            'licence_no' => 'N02-23-456789',
            'licence_expiry' => '2029-01-01',
        ]);

        Employee::create([
            'employee_no' => 'EMP-0002',
            'first_name' => 'Jun',
            'last_name' => 'Abad',
            'position' => 'Driver',
            'hired_on' => '2026-01-01',
            'contact' => '0918 555 0202',
            'driver_id' => $second->id,
        ]);

        ($this->trip)();
        ($this->trip)();
        ($this->trip)(['driver_id' => $second->id]);

        $board = $this->actingAs($this->admin)->getJson('/api/v1/hr/performance')->assertOk();

        expect($board->json('data.crew.0.employee.name'))->toBe('Marco Reyes');
        expect($board->json('data.crew.0.trips_completed'))->toBe(2);
        expect($board->json('data.crew.1.trips_completed'))->toBe(1);
        expect($board->json('data.totals.crew'))->toBe(2);
        expect($board->json('data.totals.trips_completed'))->toBe(3);
        expect($board->json('data.totals.revenue_cents'))->toBe(1_200_000);
    });

    it('leaves out crew with no work in the window', function (): void {
        Employee::create([
            'employee_no' => 'EMP-0002',
            'first_name' => 'Jun',
            'last_name' => 'Abad',
            'position' => 'Driver',
            'hired_on' => '2026-01-01',
            'contact' => '0918 555 0202',
            'driver_id' => Driver::create([
                'name' => 'Jun Abad',
                'licence_no' => 'N02-23-456789',
                'licence_expiry' => '2029-01-01',
            ])->id,
        ]);

        ($this->trip)();

        expect($this->actingAs($this->admin)->getJson('/api/v1/hr/performance')->json('data.crew'))
            ->toHaveCount(1);
    });

    it('has no on-time rate for a period nobody delivered in', function (): void {
        ($this->trip)(['status' => StatusValue::Assigned->value]);

        $totals = $this->actingAs($this->admin)->getJson('/api/v1/hr/performance')->json('data.totals');

        expect($totals['trips_completed'])->toBe(0);
        expect($totals['on_time_rate'])->toBeNull();
    });
});
