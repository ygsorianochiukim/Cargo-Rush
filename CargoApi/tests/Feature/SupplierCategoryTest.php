<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierCategory;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(NavigationSeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => Role::Administrator->value,
    ]);

    $this->add = fn (string $name) => $this->actingAs($this->admin)
        ->postJson('/api/v1/supplier-categories', ['name' => $name]);
});

it('adds, lists and renames categories', function (): void {
    foreach (['GARAGE', 'MALL', 'SUPPLIER', 'FOODS'] as $name) {
        ($this->add)($name)->assertCreated()->assertJsonPath('data.name', $name);
    }

    $list = $this->actingAs($this->admin)->getJson('/api/v1/supplier-categories')->assertOk();

    expect(array_column($list->json('data'), 'name'))->toBe(['FOODS', 'GARAGE', 'MALL', 'SUPPLIER'])
        ->and($list->json('meta.total'))->toBe(4);

    $garage = SupplierCategory::where('name', 'GARAGE')->firstOrFail();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/supplier-categories/{$garage->id}", ['name' => 'GARAGES'])
        ->assertOk()
        ->assertJsonPath('data.name', 'GARAGES');
});

it('refuses a second category by the same name', function (): void {
    ($this->add)('GARAGE')->assertCreated();
    ($this->add)('GARAGE')->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('files a supplier under a category and shows it', function (): void {
    $category = ($this->add)('GARAGE')->json('data');

    $supplier = $this->actingAs($this->admin)->postJson('/api/v1/suppliers', [
        'name' => 'Davao Lubes & Parts',
        'category_id' => $category['id'],
    ])->assertCreated()->json('data');

    expect($supplier['category_id'])->toBe($category['id'])
        ->and($supplier['category_name'])->toBe('GARAGE');

    $listed = $this->actingAs($this->admin)->getJson('/api/v1/suppliers')->json('data.0');
    expect($listed['category_name'])->toBe('GARAGE');

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/suppliers/{$supplier['id']}", ['category_id' => null])
        ->assertOk()
        ->assertJsonPath('data.category_name', null);
});

it('refuses a category that does not exist', function (): void {
    $this->actingAs($this->admin)->postJson('/api/v1/suppliers', [
        'name' => 'Davao Lubes & Parts',
        'category_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
    ])->assertUnprocessable()->assertJsonValidationErrors('category_id');
});

it('deletes an unused category, and retires a used one', function (): void {
    $unused = ($this->add)('MALL')->json('data');
    $used = ($this->add)('GARAGE')->json('data');
    Supplier::factory()->create(['name' => 'Davao Lubes', 'category_id' => $used['id']]);

    $this->actingAs($this->admin)->deleteJson("/api/v1/supplier-categories/{$unused['id']}")->assertNoContent();
    expect(SupplierCategory::find($unused['id']))->toBeNull();

    $this->actingAs($this->admin)->deleteJson("/api/v1/supplier-categories/{$used['id']}")
        ->assertOk()
        ->assertJsonPath('meta.retired', true)
        ->assertJsonPath('data.status', 'inactive');

    expect(Supplier::where('name', 'Davao Lubes')->first()->category?->name)->toBe('GARAGE');

    $active = $this->actingAs($this->admin)->getJson('/api/v1/supplier-categories?active=1')->json('data');
    expect($active)->toBe([]);
});
