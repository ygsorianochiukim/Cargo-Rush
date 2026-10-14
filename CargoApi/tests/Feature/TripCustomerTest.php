<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\NavigationSeeder;

beforeEach(function (): void {
    zoneCard();

    $this->seed(NavigationSeeder::class);

    $this->admin = User::factory()->create(['role' => Role::Administrator]);

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

it('books a trip for a customer already on the list', function (): void {
    $customer = Customer::create(['name' => 'Gaisano Mall', 'contact' => '088 555 0101']);

    ($this->book)(['customer_id' => $customer->id])
        ->assertCreated()
        ->assertJsonPath('data.customer_id', $customer->id)
        ->assertJsonPath('data.customer', 'Gaisano Mall');
});

it('adds a customer typed on the trip to Customer Management', function (): void {
    $trip = ($this->book)([
        'new_customer' => ['name' => 'Limketkai Center', 'contact' => '088 555 0202', 'address' => 'Lapasan, CDO'],
    ])->assertCreated()->json('data');

    $customer = Customer::query()->where('name', 'Limketkai Center')->firstOrFail();

    expect($trip['customer_id'])->toBe($customer->id)
        ->and($customer->contact)->toBe('088 555 0202')
        ->and($customer->address)->toBe('Lapasan, CDO');

    $this->actingAs($this->admin)->getJson('/api/v1/customers')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Limketkai Center']);
});

it('reuses a customer when the typed name is already on the list', function (): void {
    $customer = Customer::create(['name' => 'Gaisano Mall', 'contact' => '088 555 0101']);

    ($this->book)(['new_customer' => ['name' => 'gaisano mall', 'contact' => '088 555 9999']])
        ->assertCreated()
        ->assertJsonPath('data.customer_id', $customer->id);

    expect(Customer::query()->count())->toBe(1);
});

it('needs a contact for a new customer', function (): void {
    ($this->book)(['new_customer' => ['name' => 'Nameless Contact']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['new_customer.contact']);

    expect(Customer::query()->count())->toBe(0);
});

it('leaves no customer behind when the trip itself is refused', function (): void {
    ($this->book)([
        'new_customer' => ['name' => 'Rolled Back Inc', 'contact' => '088 555 0303'],
        'status' => 'assigned',
        'price_cents' => null,
        'distance_total_m' => 0,
        'driver_id' => 'not-a-driver',
    ])->assertUnprocessable();

    expect(Customer::query()->where('name', 'Rolled Back Inc')->exists())->toBeFalse()
        ->and(Trip::query()->count())->toBe(0);
});

it('sets the customer on an existing trip from the edit form', function (): void {
    $id = ($this->book)()->json('data.id');

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$id}", ['new_customer' => ['name' => 'SM CDO', 'contact' => '088 555 0404']])
        ->assertOk()
        ->assertJsonPath('data.customer', 'SM CDO');
});
