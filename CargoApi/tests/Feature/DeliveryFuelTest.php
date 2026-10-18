<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\PaymentSource;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    zoneCard();

    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create(['role' => Role::Administrator]);

    $this->log = function (StatusValue $status = StatusValue::Delivered): DeliveryLog {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            'origin' => 'Cagayan de Oro',
            'destination' => 'Iligan',
            'cargo' => 'Dry goods',
            'weight_kg' => 500,
            'distance_total_m' => 34_000,
            'scheduled_at' => now()->addHours(3)->toIso8601String(),
        ])->assertCreated()->json('data.id');

        $log = DeliveryLog::where('trip_id', $id)->firstOrFail();
        $log->update([
            'delivered_at' => $status === StatusValue::Delivered ? now() : null,
            'status' => $status->value,
        ]);

        return $log;
    };
});

it('starts a delivery log without any fuel', function (): void {
    $log = ($this->log)();

    $this->actingAs($this->admin)->getJson("/api/v1/delivery-logs/{$log->id}")
        ->assertOk()
        ->assertJsonPath('data.fuel_litres', null)
        ->assertJsonPath('data.fuel_cost_cents', null);
});

it('encodes the fuel used and its cost at the end of a trip', function (): void {
    $log = ($this->log)();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/delivery-logs/{$log->id}/fuel", ['fuel_litres' => 42.5, 'fuel_cost_cents' => 255_000])
        ->assertOk()
        ->assertJsonPath('data.fuel_litres', 42.5)
        ->assertJsonPath('data.fuel_cost_cents', 255_000);

    expect($log->refresh()->fuel_cost_cents)->toBe(255_000);
});

it('records which wallet or card paid for the trip\'s fuel', function (): void {
    $log = ($this->log)();
    $shell = PaymentSource::create(['name' => 'Shell Fleet Card', 'kind' => 'fuel_card', 'last_four' => '0093']);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/delivery-logs/{$log->id}/fuel", [
            'fuel_litres' => 40, 'fuel_cost_cents' => 240_000, 'payment_source_id' => $shell->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.payment_source_id', $shell->id)
        ->assertJsonPath('data.payment_source_label', 'Shell Fleet Card ···· 0093');

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/delivery-logs/{$log->id}/fuel", ['fuel_litres' => 41, 'fuel_cost_cents' => 246_000])
        ->assertOk()
        ->assertJsonPath('data.payment_source_id', $shell->id);

    $row = collect($this->actingAs($this->admin)->getJson('/api/v1/payment-sources')->json('data'))
        ->firstWhere('id', $shell->id);

    expect($row['fuel_spend_cents'])->toBe(246_000);
});

it('clears the fuel when both are left blank', function (): void {
    $log = ($this->log)();
    $log->update(['fuel_litres' => 30, 'fuel_cost_cents' => 180_000]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/delivery-logs/{$log->id}/fuel", ['fuel_litres' => null, 'fuel_cost_cents' => null])
        ->assertOk()
        ->assertJsonPath('data.fuel_litres', null)
        ->assertJsonPath('data.fuel_cost_cents', null);
});

it('waits for the trip to be delivered before taking fuel', function (): void {
    $log = ($this->log)(StatusValue::Pending);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/delivery-logs/{$log->id}/fuel", ['fuel_litres' => 10, 'fuel_cost_cents' => 60_000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fuel_litres');
});

it('refuses a negative fuel cost', function (): void {
    $log = ($this->log)();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/delivery-logs/{$log->id}/fuel", ['fuel_litres' => 10, 'fuel_cost_cents' => -5])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fuel_cost_cents');
});

it('keeps a driver off the fuel of a trip they did not run', function (): void {
    $log = ($this->log)();
    $driver = User::factory()->create(['role' => Role::Driver]);

    $this->actingAs($driver)
        ->patchJson("/api/v1/delivery-logs/{$log->id}/fuel", ['fuel_litres' => 10, 'fuel_cost_cents' => 60_000])
        ->assertNotFound();

    expect($log->refresh()->fuel_litres)->toBeNull();
});
