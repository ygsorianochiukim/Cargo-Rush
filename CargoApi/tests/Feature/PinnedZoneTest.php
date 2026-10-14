<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\NavigationSeeder;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);

    $this->admin = User::factory()->create(['role' => Role::Administrator]);

    $band = fn (array $zone) => $this->actingAs($this->admin)->postJson('/api/v1/pricing/zones', [
        'diesel_baseline_cents' => 4_300,
        ...$zone,
    ])->assertCreated()->json('data');

    $this->a1 = $band([
        'name' => 'Zone A1', 'code' => 'A1', 'min_km' => 0, 'max_km' => 41, 'position' => 0,
        'brackets' => [['label' => 'Current fleet', 'base_cents' => 416_500]],
    ]);

    $this->b = $band([
        'name' => 'Zone B', 'code' => 'B', 'min_km' => 41, 'max_km' => 81, 'position' => 1,
        'brackets' => [['label' => 'Current fleet', 'base_cents' => 523_000]],
    ]);

    $this->book = fn (array $overrides = []) => $this->actingAs($this->admin)->postJson('/api/v1/trips', [
        'origin' => 'Cagayan de Oro',
        'destination' => 'Iligan',
        'cargo' => 'Dry goods',
        'weight_kg' => 500,
        'distance_total_m' => 34_000,
        'scheduled_at' => now()->addHours(3)->toIso8601String(),
        ...$overrides,
    ]);
});

it('prices the zone the desk picked even when the measured distance says otherwise', function (): void {
    $trip = ($this->book)(['pricing_zone_id' => $this->b['id']])->assertCreated()->json('data');

    expect($trip['price_cents'])->toBe(523_000)
        ->and($trip['pricing_zone_id'])->toBe($this->b['id'])
        ->and($trip['zone_pinned'])->toBeTrue();
});

it('keeps the picked zone when the distance is measured again', function (): void {
    $id = ($this->book)(['pricing_zone_id' => $this->b['id']])->json('data.id');

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$id}", ['distance_total_m' => 12_000])
        ->assertOk()
        ->assertJsonPath('data.price_cents', 523_000);
});

it('goes back to the distance once the pick is cleared', function (): void {
    $id = ($this->book)(['pricing_zone_id' => $this->b['id']])->json('data.id');

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$id}", ['pricing_zone_id' => null])
        ->assertOk()
        ->assertJsonPath('data.price_cents', 416_500)
        ->assertJsonPath('data.zone_pinned', false)
        ->assertJsonPath('data.pricing_zone_id', $this->a1['id']);
});

it('still re-bands an unpinned trip by its distance', function (): void {
    $id = ($this->book)()->json('data.id');

    expect(Trip::findOrFail($id)->zone_pinned)->toBeFalse();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$id}", ['distance_total_m' => 60_000])
        ->assertOk()
        ->assertJsonPath('data.pricing_zone_id', $this->b['id']);
});

it('pins the zone picked when confirming a request', function (): void {
    $id = ($this->book)(['status' => 'pending'])->json('data.id');

    $driver = $this->actingAs($this->admin)->postJson('/api/v1/drivers', [
        'name' => 'Marco Reyes', 'licence_no' => 'N01-00-000001', 'licence_expiry' => '2029-08-31',
    ])->json('data.id');

    $vehicle = $this->actingAs($this->admin)->postJson('/api/v1/vehicles', [
        'plate' => 'NCR 4412', 'model' => 'Isuzu Elf', 'registration_no' => 'REG-4412', 'capacity_kg' => 4000,
    ])->json('data.id');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/trips/{$id}/confirm", [
            'driver_id' => $driver,
            'vehicle_id' => $vehicle,
            'scheduled_at' => now()->addHours(3)->toIso8601String(),
            'pricing_zone_id' => $this->b['id'],
        ])
        ->assertOk()
        ->assertJsonPath('data.price_cents', 523_000)
        ->assertJsonPath('data.zone_pinned', true);
});

it('previews a picked zone outside the distance, and says it was picked', function (): void {
    $quote = $this->actingAs($this->admin)->postJson('/api/v1/pricing/quote', [
        'distance_km' => 30,
        'pricing_zone_id' => $this->b['id'],
    ])->assertOk();

    expect($quote->json('data.zone.code'))->toBe('B')
        ->and($quote->json('data.cents'))->toBe(523_000)
        ->and($quote->json('data.zone_pinned'))->toBeTrue()
        ->and($quote->json('data.km'))->toBe(30);
});

it('re-prices a delivered, unpaid run when the desk picks another zone', function (): void {
    $customer = Customer::create(['name' => 'Pryce Gases', 'contact' => '0917 000 0000']);

    $id = ($this->book)([
        'customer_id' => $customer->id,
        'scheduled_at' => now()->subDay()->toIso8601String(),
        'status' => StatusValue::Delivered->value,
    ])->assertCreated()->assertJsonPath('data.price_cents', 416_500)->json('data.id');

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$id}", ['pricing_zone_id' => $this->b['id']])
        ->assertOk()
        ->assertJsonPath('data.price_cents', 523_000)
        ->assertJsonPath('data.zone_pinned', true)
        ->assertJsonPath('data.status', StatusValue::Delivered->value);

    $invoice = Invoice::query()->where('trip_id', $id)->firstOrFail();

    expect($invoice->taxBaseCents())->toBe(523_000)
        ->and(Invoice::query()->where('trip_id', $id)->count())->toBe(1);
});

it('leaves a delivered run at its price when only a note changes', function (): void {
    $id = ($this->book)([
        'scheduled_at' => now()->subDay()->toIso8601String(),
        'status' => StatusValue::Delivered->value,
    ])->assertCreated()->json('data.id');

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$id}", ['cargo' => 'Dry goods, 14 pallets', 'weight_kg' => 500])
        ->assertOk()
        ->assertJsonPath('data.price_cents', 416_500);
});
