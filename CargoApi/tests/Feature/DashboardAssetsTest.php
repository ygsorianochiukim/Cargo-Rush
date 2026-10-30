<?php

declare(strict_types=1);

use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(NavigationSeeder::class);

    $this->admin = User::factory()->create(['role' => 'administrator']);

    $this->register = fn (string $tag, int $cost, array $payload = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/assets', [
            'name' => 'Item '.$tag,
            'tag' => $tag,
            'purchased_on' => '2026-02-10',
            'cost_cents' => $cost,
            ...$payload,
        ])->assertCreated()->json('data');

    $this->summary = fn () => $this->actingAs($this->admin)
        ->getJson('/api/v1/dashboard/assets')
        ->assertOk()
        ->json('data');
});

it('adds up what every asset on the register cost', function (): void {
    $marco = Employee::create([
        'employee_no' => 'EMP-0001', 'first_name' => 'Marco', 'last_name' => 'Reyes',
        'position' => 'Dispatcher', 'hired_on' => '2026-01-05', 'contact' => '0917 555 0101',
    ]);

    ($this->register)('IT-0001', 4_250_000, ['custodian_id' => $marco->id]);
    ($this->register)('IT-0002', 1_800_000);
    ($this->register)('IT-0003', 350_000, ['status' => 'maintenance']);
    ($this->register)('IT-0004', 0, ['status' => 'inactive']);

    $summary = ($this->summary)();

    expect($summary['cost_cents'])->toBe(6_400_000)
        ->and($summary['count'])->toBe(4)
        ->and($summary['assigned'])->toBe(1)
        ->and($summary['available'])->toBe(1)
        ->and($summary['maintenance'])->toBe(1)
        ->and($summary['inactive'])->toBe(1)
        ->and($summary['currency'])->toBe('PHP');
});

it('answers zero rather than an error when nothing is registered', function (): void {
    $summary = ($this->summary)();

    expect($summary['cost_cents'])->toBe(0)
        ->and($summary['count'])->toBe(0)
        ->and($summary['assigned'])->toBe(0);
});

it('keeps the figure from anyone who cannot see the register', function (): void {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);

    $this->actingAs($dispatcher)->getJson('/api/v1/dashboard/assets')->assertForbidden();
});
