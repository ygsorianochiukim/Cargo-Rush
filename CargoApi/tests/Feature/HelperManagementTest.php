<?php

declare(strict_types=1);

use App\Domain\Driver\Models\Driver;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PositionSeeder;

beforeEach(function (): void {
    zoneCard();

    $this->seed(NavigationSeeder::class);
    $this->seed(PositionSeeder::class);

    $this->admin = User::factory()->create(['role' => Role::Administrator]);

    $this->addCrew = fn (array $payload) => $this->actingAs($this->admin)->postJson('/api/v1/drivers', $payload);

    $this->driverPayload = [
        'name' => 'Juan Dela Cruz',
        'contact' => '0917 555 0101',
        'licence_no' => 'N02-14-882301',
        'licence_expiry' => '2029-08-31',
    ];

    $this->helperPayload = [
        'role' => 'helper',
        'name' => 'Pedro Santos',
        'contact' => '0917 555 0202',
    ];
});

describe('the helper roster', function (): void {
    it('adds a helper without a licence', function (): void {
        $helper = ($this->addCrew)($this->helperPayload)->assertCreated()->json('data');

        expect($helper['role'])->toBe('helper')
            ->and($helper['licence_no'])->toBeNull()
            ->and($helper['licence_expiring'])->toBeFalse();
    });

    it('still wants a licence for a driver', function (): void {
        ($this->addCrew)(['name' => 'No Licence'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['licence_no', 'licence_expiry']);
    });

    it('keeps helpers off the driver roster and lists them on their own', function (): void {
        ($this->addCrew)($this->driverPayload)->assertCreated();
        ($this->addCrew)($this->helperPayload)->assertCreated();

        $list = fn (string $query) => collect($this->actingAs($this->admin)->getJson("/api/v1/drivers{$query}")
            ->assertOk()->json('data'))->pluck('name')->all();

        expect($list(''))->toBe(['Juan Dela Cruz'])
            ->and($list('?role=helper'))->toBe(['Pedro Santos'])
            ->and($list('?role=all'))->toBe(['Juan Dela Cruz', 'Pedro Santos']);
    });

    it('will not put a helper behind the wheel, but takes one along', function (): void {
        $driver = ($this->addCrew)($this->driverPayload)->json('data.id');
        $helper = ($this->addCrew)($this->helperPayload)->json('data.id');

        $book = fn (array $crew) => $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            'origin' => 'Manila',
            'destination' => 'Batangas',
            'cargo' => 'Cement',
            'weight_kg' => 3200,
            'scheduled_at' => now()->addHour()->toIso8601String(),
            ...$crew,
        ]);

        $book(['driver_id' => $helper])
            ->assertUnprocessable()
            ->assertJsonPath('errors.driver_id.0', 'Pick someone from the driver roster to take the wheel.');

        $book(['driver_id' => $driver, 'helper_ids' => [$helper]])
            ->assertCreated()
            ->assertJsonPath('data.helper_ids', [$helper]);
    });
});

describe('crew on the employee list', function (): void {
    it('enrols a new driver as an employee in the Driver position', function (): void {
        $driver = ($this->addCrew)($this->driverPayload)->assertCreated()->json('data');

        $employee = Employee::query()->where('driver_id', $driver['id'])->firstOrFail();

        expect($employee->first_name)->toBe('Juan Dela')
            ->and($employee->last_name)->toBe('Cruz')
            ->and($employee->contact)->toBe('0917 555 0101')
            ->and($employee->position)->toBe('Driver')
            ->and($employee->position_id)->toBe(Position::where('key', 'driver')->value('id'))
            ->and($employee->isActive())->toBeTrue()
            ->and($driver['employee_no'])->toBe($employee->employee_no);
    });

    it('enrols a new helper in the Helper position', function (): void {
        $helper = ($this->addCrew)($this->helperPayload)->json('data');

        expect(Employee::query()->where('driver_id', $helper['id'])->value('position'))->toBe('Helper');
    });

    it('shows them on the employees endpoint', function (): void {
        ($this->addCrew)($this->helperPayload)->assertCreated();

        $names = collect($this->actingAs($this->admin)->getJson('/api/v1/employees')->assertOk()->json('data'))
            ->map(fn (array $e): string => "{$e['first_name']} {$e['last_name']}")
            ->all();

        expect($names)->toContain('Pedro Santos');
    });

    it('carries a renamed driver over to the employee record', function (): void {
        $id = ($this->addCrew)($this->driverPayload)->json('data.id');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/drivers/{$id}", ['name' => 'Juan Reyes', 'contact' => '0918 000 0000'])
            ->assertOk();

        $employee = Employee::query()->where('driver_id', $id)->firstOrFail();

        expect($employee->fullName())->toBe('Juan Reyes')
            ->and($employee->contact)->toBe('0918 000 0000')
            ->and(Employee::query()->where('driver_id', $id)->count())->toBe(1);
    });

    it('puts an employee hired as a helper on the helper roster', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/employees', [
            'first_name' => 'Rico',
            'last_name' => 'Bautista',
            'position_id' => Position::where('key', 'helper')->value('id'),
            'hired_on' => '2026-03-01',
            'contact' => '0918 555 0303',
        ])->assertCreated();

        $helper = Driver::query()->where('name', 'Rico Bautista')->firstOrFail();

        expect($helper->isHelper())->toBeTrue()
            ->and(Employee::query()->where('driver_id', $helper->id)->exists())->toBeTrue()
            ->and(Employee::query()->count())->toBe(1);
    });
});
