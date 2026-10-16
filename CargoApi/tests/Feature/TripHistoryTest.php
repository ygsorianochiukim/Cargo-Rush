<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->customer = Customer::firstOrFail();

    $this->id = $this->actingAs($this->admin)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->postJson('/api/v1/trips', [
            'origin' => 'Cagayan de Oro',
            'destination' => 'Iligan',
            'cargo' => 'Assorted retail',
            'weight_kg' => 2400,
            'price_cents' => 500_000,
            'customer_id' => $this->customer->id,
            'driver_id' => Driver::where('name', 'Marco Reyes')->firstOrFail()->id,
            'vehicle_id' => Vehicle::where('plate', 'NCR 4412')->firstOrFail()->id,
            'scheduled_at' => '2026-06-10 08:00:00',
            'status' => StatusValue::Delivered->value,
        ])
        ->assertCreated()
        ->json('data.id');

    $this->history = fn (): array => $this->actingAs($this->admin)
        ->getJson("/api/v1/trips/{$this->id}/history")
        ->assertOk()
        ->json('data');
});

it('shows who changed the trip, when, from which address and what moved', function (): void {
    $this->actingAs($this->admin)
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.24'])
        ->patchJson("/api/v1/trips/{$this->id}", ['price_cents' => 650_000])
        ->assertOk();

    $edit = collect(($this->history)())
        ->first(fn (array $row): bool => $row['subject'] === 'Trip'
            && $row['action'] === 'updated'
            && collect($row['changes'])->contains('field', 'price_cents'));

    $price = collect($edit['changes'])->firstWhere('field', 'price_cents');

    expect($edit['user'])->toBe($this->admin->name)
        ->and($edit['ip_address'])->toBe('198.51.100.24')
        ->and($edit['at'])->not->toBeEmpty()
        ->and($edit['url'])->toBe("PATCH api/v1/trips/{$this->id}")
        ->and($price['from'])->toBe(500_000)
        ->and($price['to'])->toBe(650_000);
});

it('records who created it and names the customer rather than its id', function (): void {
    $created = collect(($this->history)())
        ->first(fn (array $row): bool => $row['subject'] === 'Trip' && $row['action'] === 'created');

    expect($created['user'])->toBe($this->admin->name)
        ->and($created['ip_address'])->toBe('203.0.113.7')
        ->and(collect($created['changes'])->firstWhere('field', 'customer_id')['to'])->toBe($this->customer->name);
});

it('includes its invoice and the payments made against it', function (): void {
    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$this->id}", ['payment_status' => 'paid', 'payment_reference' => 'OR-77'])
        ->assertOk();

    $subjects = collect(($this->history)())->pluck('subject');

    expect($subjects->contains(fn (string $s): bool => str_starts_with($s, 'Invoice INV-')))->toBeTrue()
        ->and($subjects)->toContain('Payment')
        ->and($subjects)->toContain('Delivery');
});

it('is only for people who can see trips', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $nobody = User::factory()->create(['role' => 'hr-officer', 'company_id' => $this->admin->company_id]);

    $this->actingAs($nobody)->getJson("/api/v1/trips/{$this->id}/history")->assertForbidden();
});
