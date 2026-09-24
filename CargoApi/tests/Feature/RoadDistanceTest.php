<?php

declare(strict_types=1);

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\SubsidyRateCardSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The zone a run is quoted in comes from the road, not the straight line.
 *
 * A zone is a band of kilometres, so the distance decides the price. CDO to
 * Iligan is 49 km between the pins and about 90 km by road: band B on the
 * straight line, band C on the road the truck actually takes — ₱5,230 against
 * ₱6,530 on the subsidy card. These pin the road figure through both ways a
 * run is booked, a customer's request from the app and the desk's own form.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(SubsidyRateCardSeeder::class);

    config(['cargo.routing.ors_key' => 'test-key']);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->buyer = User::where('email', 'orders@negrosfresh.ph')->firstOrFail();

    // Cagayan de Oro to Iligan City.
    $this->cdo = ['lat' => 8.4542, 'lng' => 124.6319];
    $this->iligan = ['lat' => 8.2280, 'lng' => 124.2452];

    /** The routing service answering with a road distance, in metres. */
    $this->road = fn (int $metres) => Http::fake([
        'api.openrouteservice.org/*' => Http::response(['routes' => [['summary' => ['distance' => $metres]]]]),
    ]);

    $this->pins = fn (array $from, array $to) => [
        'origin_lat' => $from['lat'], 'origin_lng' => $from['lng'],
        'destination_lat' => $to['lat'], 'destination_lng' => $to['lng'],
    ];

    $this->zoneOf = fn (string $tripId): ?string => PricingZone::find(Trip::findOrFail($tripId)->pricing_zone_id)?->code;

    $this->book = fn (array $overrides = []) => $this->actingAs($this->admin)->postJson('/api/v1/trips', [
        'origin' => 'Cagayan de Oro',
        'destination' => 'Iligan City',
        'cargo' => 'Cement, 200 bags',
        'weight_kg' => 3200,
        'driver_id' => Driver::where('name', 'Marco Reyes')->value('id'),
        'vehicle_id' => Vehicle::where('plate', 'NCR 4412')->value('id'),
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'status' => StatusValue::Scheduled->value,
        ...($this->pins)($this->cdo, $this->iligan),
        ...$overrides,
    ]);
});

it('quotes a customer’s pinned request in the band the road puts it in', function (): void {
    ($this->road)(89_600);

    $id = $this->actingAs($this->buyer)->postJson('/api/v1/portal/requests', [
        'origin' => 'Cagayan de Oro',
        'destination' => 'Iligan City',
        'cargo' => 'Chilled produce, 8 crates',
        'weight_kg' => 1800,
        'preferred_at' => now()->addDay()->toIso8601String(),
        ...($this->pins)($this->cdo, $this->iligan),
    ])->assertCreated()->json('data.id');

    $trip = Trip::findOrFail($id);

    // Band C, 81–120 km. On the straight line this was B.
    expect($trip->distance_total_m)->toBe(89_600)
        ->and($trip->distance_source)->toBe('road')
        ->and(($this->zoneOf)($id))->toBe('C')
        ->and($trip->price_cents)->toBeGreaterThanOrEqual(653_000);
});

it('quotes the desk’s own booking on the road too, asking for a truck’s route', function (): void {
    ($this->road)(89_600);

    $trip = ($this->book)()->assertCreated()->json('data');

    expect($trip['distance_total_m'])->toBe(89_600)
        ->and($trip['distance_source'])->toBe('road')
        ->and(($this->zoneOf)($trip['id']))->toBe('C');

    // Heavy-goods profile, longitude first, key in the header rather than the URL.
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v2/directions/driving-hgv')
        && $request->hasHeader('Authorization', 'test-key')
        && $request['coordinates'] === [[124.6319, 8.4542], [124.2452, 8.2280]]);
});

it('measures again, and re-quotes, when a pin is moved', function (): void {
    Http::fakeSequence('api.openrouteservice.org/*')
        ->push(['routes' => [['summary' => ['distance' => 89_600]]]])
        ->push(['routes' => [['summary' => ['distance' => 60_000]]]]);

    $id = ($this->book)()->json('data.id');

    // The destination corrected to a warehouse short of Iligan, 60 km out.
    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$id}", ['destination_lat' => 8.3500, 'destination_lng' => 124.4000])
        ->assertOk()
        ->assertJsonPath('data.distance_total_m', 60_000);

    expect(($this->zoneOf)($id))->toBe('B');
});

it('does not spend a routing call on a save that leaves the route alone', function (): void {
    ($this->road)(89_600);
    $id = ($this->book)()->json('data.id');

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$id}", ['cargo' => 'Cement, 180 bags'])
        ->assertOk()
        ->assertJsonPath('data.distance_total_m', 89_600);

    Http::assertSentCount(1);
});

it('keeps a distance the desk typed, and says it was typed', function (): void {
    ($this->road)(89_600);

    $trip = ($this->book)(['distance_total_m' => 95_000])->assertCreated()->json('data');

    expect($trip['distance_total_m'])->toBe(95_000)
        ->and($trip['distance_source'])->toBe('manual');

    Http::assertNothingSent();
});

it('falls back to an estimate, marked as one, when the service cannot answer', function (): void {
    Http::fake(['api.openrouteservice.org/*' => Http::response(['error' => 'quota'], 429)]);

    $trip = ($this->book)()->assertCreated()->json('data');

    // 49 km in a straight line, times the 1.4 detour factor.
    expect($trip['distance_source'])->toBe('estimate')
        ->and($trip['distance_total_m'])->toBeGreaterThan(65_000)->toBeLessThan(72_000);
});

it('asks once for a route it has already measured', function (): void {
    ($this->road)(89_600);

    ($this->book)()->assertCreated();
    ($this->book)()->assertCreated();

    Http::assertSentCount(1);
});

describe('re-measuring what is already on the board', function (): void {
    beforeEach(function (): void {
        // Booked before the routing key existed: measured on the estimate.
        config(['cargo.routing.ors_key' => null]);
        $this->quoted = ($this->book)()->json('data.id');
        $this->negotiated = ($this->book)(['price_cents' => 500_000])->json('data.id');
        config(['cargo.routing.ors_key' => 'test-key']);
    });

    it('measures on the road and re-quotes a trip the system priced', function (): void {
        ($this->road)(89_600);

        $this->artisan('cargo:trips-remeasure')->assertSuccessful();

        $trip = Trip::findOrFail($this->quoted);

        expect($trip->distance_total_m)->toBe(89_600)
            ->and($trip->distance_source)->toBe('road')
            ->and(($this->zoneOf)($this->quoted))->toBe('C');
    });

    it('moves the distance but keeps a price somebody negotiated', function (): void {
        ($this->road)(89_600);

        $this->artisan('cargo:trips-remeasure')->assertSuccessful();

        $trip = Trip::findOrFail($this->negotiated);

        expect($trip->distance_total_m)->toBe(89_600)
            ->and($trip->price_cents)->toBe(500_000);
    });

    it('changes nothing on a dry run', function (): void {
        ($this->road)(89_600);
        $before = Trip::findOrFail($this->quoted)->only(['distance_total_m', 'price_cents']);

        $this->artisan('cargo:trips-remeasure', ['--dry-run' => true])->assertSuccessful();

        expect(Trip::findOrFail($this->quoted)->only(['distance_total_m', 'price_cents']))->toBe($before);
    });
});
