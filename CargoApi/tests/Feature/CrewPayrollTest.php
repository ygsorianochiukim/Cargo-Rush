<?php

declare(strict_types=1);

use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->crew = function (string $name, string $role = 'driver', string $employment = 'company', ?int $rateCents = 150_000): array {
        static $n = 0;

        $driver = $this->actingAs($this->admin)->postJson('/api/v1/drivers', [
            'role' => $role,
            'employment' => $employment,
            'name' => $name,
            'contact' => '0917 555 01'.str_pad((string) ++$n, 2, '0', STR_PAD_LEFT),
            ...($role === 'driver' ? ['licence_no' => 'N0'.$n.'-23-45678'.$n, 'licence_expiry' => '2029-08-31'] : []),
        ])->assertCreated()->json('data');

        if ($driver['employee_id'] !== null && $rateCents !== null) {
            payContract(Employee::findOrFail($driver['employee_id']), 'per_trip', $rateCents, '2026-01-05');
        }

        return $driver;
    };

    $this->office = fn (string $first = 'Elena') => $this->actingAs($this->admin)
        ->postJson('/api/v1/employees', [
            'first_name' => $first,
            'last_name' => 'Bautista',
            'position' => 'Office Staff',
            'department' => 'Administration',
            'contact' => '0917 555 0199',
            'hired_on' => '2026-01-05',
            'amount_cents' => 3_000_000,
        ])->assertCreated()->json('data');

    $this->haul = function (string $date, string $driverId, array $helperIds = [], ?string $vehicleId = null): Trip {
        static $n = 0;

        $trip = Trip::create([
            'reference' => 'CREW-'.str_pad((string) ++$n, 4, '0', STR_PAD_LEFT),
            'origin' => 'Davao City',
            'destination' => 'Tagum City',
            'cargo' => 'Assorted',
            'weight_kg' => 2000,
            'driver_id' => $driverId,
            'vehicle_id' => $vehicleId,
            'status' => 'delivered',
            'scheduled_at' => $date.' 07:00:00',
        ]);

        $trip->setHelpers($helperIds);

        return $trip;
    };

    $this->open = fn (string $scope, array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll', [
            'scope' => $scope,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'pay_date' => '2026-09-15',
            ...$overrides,
        ]);
});

describe('who goes on which run', function (): void {
    it('pays the company’s own crew on the crew run and nobody from the office', function (): void {
        ($this->office)();
        $driver = ($this->crew)('Marco Villanueva');
        $helper = ($this->crew)('Pedro Santos', 'helper', rateCents: 50_000);
        ($this->haul)('2026-09-03', $driver['id'], [$helper['id']]);

        $run = ($this->open)('crew')->assertCreated()->json('data');
        $lines = collect($run['lines'])->keyBy('name');

        expect($run['scope'])->toBe('crew')
            ->and($run['scope_label'])->toBe('Crew payroll')
            ->and($run['reference'])->toStartWith('CP-')
            ->and($run['staff_count'])->toBe(2)
            ->and($lines->has('Elena Bautista'))->toBeFalse()
            ->and($lines['Marco Villanueva']['crew'])->toBeTrue()
            ->and($lines['Marco Villanueva']['trips'])->toBe(1)
            ->and($lines['Marco Villanueva']['basic_cents'])->toBe(150_000)
            ->and($lines['Pedro Santos']['trips'])->toBe(1)
            ->and($lines['Pedro Santos']['basic_cents'])->toBe(50_000);
    });

    it('pays the office on the office run and leaves every driver and helper off it', function (): void {
        ($this->office)();
        $driver = ($this->crew)('Marco Villanueva');
        ($this->haul)('2026-09-03', $driver['id']);

        $run = ($this->open)('office')->assertCreated()->json('data');

        expect($run['scope'])->toBe('office')
            ->and($run['reference'])->toStartWith('PR-')
            ->and($run['staff_count'])->toBe(1)
            ->and($run['lines'][0]['name'])->toBe('Elena Bautista')
            ->and($run['lines'][0]['crew'])->toBeFalse();
    });

    it('is the office run when no scope is named, as before', function (): void {
        ($this->office)();
        ($this->crew)('Marco Villanueva');

        $run = $this->actingAs($this->admin)->postJson('/api/v1/payroll', [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'pay_date' => '2026-09-15',
        ])->assertCreated()->json('data');

        expect($run['scope'])->toBe('office')
            ->and($run['staff_count'])->toBe(1);
    });

    it('lets the two runs cover the same cutoff, but never two of a kind', function (): void {
        ($this->office)();
        ($this->crew)('Marco Villanueva');

        ($this->open)('office')->assertCreated();
        ($this->open)('crew')->assertCreated();

        ($this->open)('office')->assertStatus(422);
        ($this->open)('crew')->assertStatus(422);
    });

    it('lists the runs of one kind when asked', function (): void {
        ($this->office)();
        ($this->crew)('Marco Villanueva');
        ($this->open)('office')->assertCreated();
        ($this->open)('crew')->assertCreated();

        $crew = $this->actingAs($this->admin)->getJson('/api/v1/payroll?scope=crew')->assertOk()->json('data');
        $all = $this->actingAs($this->admin)->getJson('/api/v1/payroll')->assertOk()->json('data');

        expect($crew)->toHaveCount(1)
            ->and($crew[0]['scope'])->toBe('crew')
            ->and($all)->toHaveCount(2);
    });

    it('keeps a run on its own kind when it is worked out again', function (): void {
        $driver = ($this->crew)('Marco Villanueva');
        ($this->haul)('2026-09-03', $driver['id']);

        $run = ($this->open)('crew')->assertCreated()->json('data');
        ($this->office)();

        $rebuilt = $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/rebuild")
            ->assertOk()->json('data');

        expect($rebuilt['scope'])->toBe('crew')
            ->and($rebuilt['reference'])->toBe($run['reference'])
            ->and($rebuilt['staff_count'])->toBe(1);
    });
});

describe('a sub-contractor’s driver', function (): void {
    it('is added to the roster without an employee record', function (): void {
        $driver = ($this->crew)('Rey Ortega', employment: 'subcontractor');

        expect($driver['employment'])->toBe('subcontractor')
            ->and($driver['on_payroll'])->toBeFalse()
            ->and($driver['employee_id'])->toBeNull()
            ->and($driver['employer']['kind'])->toBe('subcontractor');
    });

    it('is a company driver by default, enrolled as before', function (): void {
        $driver = ($this->crew)('Marco Villanueva');

        expect($driver['employment'])->toBe('company')
            ->and($driver['on_payroll'])->toBeTrue()
            ->and($driver['employee_id'])->not->toBeNull()
            ->and($driver['employer']['kind'])->toBe('fleet');
    });

    it('drives for the company and is paid on neither run', function (): void {
        $theirs = ($this->crew)('Rey Ortega', employment: 'subcontractor');
        $ours = ($this->crew)('Marco Villanueva');

        ($this->haul)('2026-09-03', $theirs['id']);
        ($this->haul)('2026-09-04', $theirs['id'], [$ours['id']]);

        $crew = ($this->open)('crew')->assertCreated()->json('data');
        $office = ($this->open)('office')->assertCreated()->json('data');

        expect($crew['staff_count'])->toBe(1)
            ->and($crew['lines'][0]['name'])->toBe('Marco Villanueva')
            ->and($crew['lines'][0]['trips'])->toBe(1)
            ->and($office['staff_count'])->toBe(0);
    });

    it('drops off the crew run once a company driver is marked as theirs', function (): void {
        $driver = ($this->crew)('Marco Villanueva');
        ($this->haul)('2026-09-03', $driver['id']);

        expect(($this->open)('crew')->assertCreated()->json('data.staff_count'))->toBe(1);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/drivers/{$driver['id']}", ['employment' => 'subcontractor'])
            ->assertOk();

        $rerun = $this->actingAs($this->admin)->getJson('/api/v1/payroll?scope=crew')->json('data.0');

        $rebuilt = $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$rerun['id']}/rebuild")
            ->assertOk()->json('data');

        expect($rebuilt['staff_count'])->toBe(0);
    });
});

describe('the trips on a crew payslip', function (): void {
    it('lists every haul with the seat they took and the truck', function (): void {
        $driver = ($this->crew)('Marco Villanueva');
        $helper = ($this->crew)('Pedro Santos', 'helper', rateCents: 50_000);

        $vehicle = Vehicle::create([
            'plate' => 'CRW 0001', 'model' => 'Isuzu Elf', 'registration_no' => 'LTO-CRW-0001', 'capacity_kg' => 4000,
            'status' => 'available',
        ]);

        $first = ($this->haul)('2026-09-03', $driver['id'], [$helper['id']], $vehicle->id);
        $second = ($this->haul)('2026-09-07', $driver['id'], [], $vehicle->id);

        $run = ($this->open)('crew')->assertCreated()->json('data');
        $lines = collect($run['lines'])->keyBy('name');

        $marco = $lines['Marco Villanueva']['trip_details'];
        $pedro = $lines['Pedro Santos']['trip_details'];

        expect($marco)->toHaveCount(2)
            ->and(array_column($marco, 'reference'))->toBe([$first->reference, $second->reference])
            ->and($marco[0]['seat'])->toBe('driver')
            ->and($marco[0]['plate'])->toBe('CRW 0001')
            ->and($marco[0]['on'])->toBe('2026-09-03')
            ->and($marco[0]['origin'])->toBe('Davao City')
            ->and($pedro)->toHaveCount(1)
            ->and($pedro[0]['reference'])->toBe($first->reference)
            ->and($pedro[0]['seat'])->toBe('helper');

        $shown = $this->actingAs($this->admin)->getJson("/api/v1/payroll/{$run['id']}")->assertOk()->json('data');

        expect(collect($shown['lines'])->keyBy('name')['Marco Villanueva']['trip_details'])->toHaveCount(2);
    });

    it('carries the list through approval', function (): void {
        $driver = ($this->crew)('Marco Villanueva');
        ($this->haul)('2026-09-03', $driver['id']);

        $run = ($this->open)('crew')->assertCreated()->json('data');

        $approved = $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")
            ->assertOk()->json('data');

        expect($approved['lines'][0]['trip_details'])->toHaveCount(1);
    });
});

describe('the payables sheet', function (): void {
    it('sends an approved crew run to the crew payroll page', function (): void {
        $driver = ($this->crew)('Marco Villanueva');
        ($this->haul)('2026-09-03', $driver['id']);

        $run = ($this->open)('crew')->assertCreated()->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();

        $groups = collect($this->actingAs($this->admin)->getJson('/api/v1/finance/payables')->assertOk()->json('data.groups'))
            ->keyBy('key');

        $line = collect($groups['payroll']['lines'])->firstWhere('id', $run['id']);

        expect($line)->not->toBeNull()
            ->and($line['settle_at'])->toBe('/payroll/crew');
    });
});
