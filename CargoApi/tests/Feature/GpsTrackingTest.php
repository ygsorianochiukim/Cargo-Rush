<?php

declare(strict_types=1);

use App\Domain\Gps\Services\GpsService;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;

/**
 * The position pipeline: the handset writes, the back office reads.
 *
 * The rule these protect is that a reading is stamped **when it was taken**,
 * not when it arrived. A truck through a dead spot posts its trail on
 * reconnect, and the office has to see where it was at the time — otherwise
 * an hour with no signal becomes an hour parked, and the average speed for the
 * whole run goes with it.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->driverUser = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->driver = $this->driverUser->driver;

    $this->trip = Trip::create([
        'origin' => 'Pagadian',
        'origin_lat' => 7.8257,
        'origin_lng' => 123.4370,
        'destination' => 'Ozamis',
        'destination_lat' => 8.1481,
        'destination_lng' => 123.8444,
        'cargo' => 'Assorted retail',
        'weight_kg' => 2400,
        'driver_id' => $this->driver->id,
        'status' => StatusValue::InTransit->value,
        'scheduled_at' => now()->subHours(2),
        'distance_total_m' => 57_000,
    ]);
});

/** Post a reading as the handset does. */
function ping(array $overrides = []): array
{
    return array_merge([
        'location' => '7.90000, 123.50000',
        'speed_kph' => 64,
        'heading' => 'NE',
        'progress_pct' => 20,
        'distance_done_m' => 11_000,
    ], $overrides);
}

it('accepts a position from the driver', function (): void {
    $this->actingAs($this->driverUser)->postJson('/api/v1/gps/pings', [
        ...ping(),
        'trip_id' => $this->trip->id,
        'recorded_at' => now()->toIso8601String(),
    ])->assertOk();

    expect($this->trip->pings()->count())->toBe(1);
});

it('refuses a reading stamped in the future', function (): void {
    // A clock-skewed handset would otherwise poison the trail.
    $this->actingAs($this->driverUser)->postJson('/api/v1/gps/pings', [
        ...ping(),
        'trip_id' => $this->trip->id,
        'recorded_at' => now()->addHour()->toIso8601String(),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('recorded_at');
});

it('keeps the time a reading was taken, not the time it arrived', function (): void {
    // What a phone posts on reconnect after twenty minutes with no signal.
    $takenAt = now()->subMinutes(20);

    $this->actingAs($this->driverUser)->postJson('/api/v1/gps/pings', [
        ...ping(),
        'trip_id' => $this->trip->id,
        'recorded_at' => $takenAt->toIso8601String(),
    ])->assertOk();

    expect($this->trip->pings()->first()->recorded_at->timestamp)
        ->toBe($takenAt->timestamp);
});

it('shows the unit on the back office dashboard once it has reported', function (): void {
    $this->actingAs($this->driverUser)->postJson('/api/v1/gps/pings', [
        ...ping(['location' => 'Dologon junction', 'progress_pct' => 62]),
        'trip_id' => $this->trip->id,
        'recorded_at' => now()->toIso8601String(),
    ]);

    $this->actingAs($this->admin)->getJson('/api/v1/gps')
        ->assertOk()
        ->assertJsonPath('data.0.reference', $this->trip->reference)
        ->assertJsonPath('data.0.location', 'Dologon junction')
        ->assertJsonPath('data.0.progress_pct', 62);
});

it('falls back to the origin for a unit that has not reported yet', function (): void {
    // Dispatched but silent. Saying where it set off from beats an empty cell.
    $this->actingAs($this->admin)->getJson('/api/v1/gps')
        ->assertOk()
        ->assertJsonPath('data.0.location', 'Pagadian')
        ->assertJsonPath('data.0.speed_kph', 0);
});

describe('average speed', function (): void {
    /**
     * Distance over time, not the mean of the reported speeds.
     *
     * A truck queueing at a port reports 0 km/h every minute. Averaging those
     * samples drags the figure far below what the run actually averaged; the
     * ground it covered over the hours it took does not.
     */
    it('ignores the samples and measures the ground covered', function (): void {
        $start = now()->subHours(2);

        // Two hours, 60 km — but half the readings are a stationary queue.
        foreach ([[0, 0], [30, 15_000], [60, 15_000], [90, 45_000], [120, 60_000]] as $i => [$minutes, $metres]) {
            $this->trip->pings()->create([
                'location' => "point {$i}",
                'speed_kph' => $minutes === 60 ? 0 : 70,
                'heading' => 'NE',
                'progress_pct' => 0,
                'distance_done_m' => $metres,
                'recorded_at' => $start->copy()->addMinutes($minutes),
            ]);
        }

        // 60 km in 2 hours is 30 km/h. A mean of the samples would say 56.
        expect(app(GpsService::class)->averageSpeed($this->trip))->toBe(30);
    });

    it('reports what it has when there is only one reading', function (): void {
        $this->trip->pings()->create([
            'location' => 'first fix',
            'speed_kph' => 48,
            'heading' => 'N',
            'progress_pct' => 0,
            'distance_done_m' => 0,
            'recorded_at' => now(),
        ]);

        expect(app(GpsService::class)->averageSpeed($this->trip))->toBe(48);
    });
});

it('gives the driver a tracking readout for their own run', function (): void {
    $this->actingAs($this->driverUser)->postJson('/api/v1/gps/pings', [
        ...ping(),
        'trip_id' => $this->trip->id,
        'recorded_at' => now()->toIso8601String(),
    ]);

    $this->actingAs($this->driverUser)
        ->getJson("/api/v1/gps/trips/{$this->trip->id}/tracking")
        ->assertOk()
        ->assertJsonPath('data.point_a', 'Pagadian')
        ->assertJsonPath('data.point_b', 'Ozamis')
        ->assertJsonPath('data.distance_total_m', 57_000);
});

it('says so plainly when a trip has never reported', function (): void {
    // An object full of zeroes would read as a stopped truck.
    $this->actingAs($this->driverUser)
        ->getJson("/api/v1/gps/trips/{$this->trip->id}/tracking")
        ->assertNotFound();
});
