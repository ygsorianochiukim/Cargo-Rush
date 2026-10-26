<?php

declare(strict_types=1);

use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->crew = function (string $name, string $employment = 'company', string $role = 'driver', int $rate = 150_000): array {
        static $n = 0;

        $driver = $this->actingAs($this->admin)->postJson('/api/v1/drivers', [
            'role' => $role,
            'employment' => $employment,
            'name' => $name,
            ...($role === 'driver' ? ['licence_no' => 'RPT-'.++$n, 'licence_expiry' => '2029-08-31'] : []),
        ])->assertCreated()->json('data');

        if ($driver['employee_id'] !== null) {
            payContract(Employee::findOrFail($driver['employee_id']), 'per_trip', $rate, '2026-01-05');
        }

        return $driver;
    };

    $this->haul = function (string $date, string $driverId, array $helperIds = []): Trip {
        static $n = 0;

        $trip = Trip::create([
            'reference' => 'RPT-'.str_pad((string) ++$n, 4, '0', STR_PAD_LEFT),
            'origin' => 'Davao City', 'destination' => 'Tagum City', 'cargo' => 'Assorted', 'weight_kg' => 2000,
            'driver_id' => $driverId, 'status' => 'delivered', 'scheduled_at' => $date.' 07:00:00',
        ]);

        $trip->setHelpers($helperIds);

        return $trip;
    };

    $this->report = fn (string $employeeId, string $from = '2026-09-01', string $to = '2026-09-30') => $this->actingAs($this->admin)
        ->getJson("/api/v1/payroll/crew-report/{$employeeId}?from={$from}&to={$to}")
        ->assertOk()
        ->json('data');
});

describe('the roster', function (): void {
    it('lists company drivers and helpers with their rate, and not a sub-contractor’s driver', function (): void {
        ($this->crew)('Marco Villanueva');
        ($this->crew)('Pedro Santos', role: 'helper', rate: 50_000);
        ($this->crew)('Rey Ortega', 'subcontractor');

        $rows = collect($this->actingAs($this->admin)->getJson('/api/v1/payroll/crew-report')->assertOk()->json('data'))
            ->keyBy('name');

        expect($rows->has('Rey Ortega'))->toBeFalse()
            ->and($rows['Marco Villanueva']['role'])->toBe('driver')
            ->and($rows['Marco Villanueva']['pay_basis'])->toBe('per_trip')
            ->and($rows['Marco Villanueva']['rate_cents'])->toBe(150_000)
            ->and($rows['Pedro Santos']['role'])->toBe('helper')
            ->and($rows['Pedro Santos']['rate_cents'])->toBe(50_000);
    });

    it('says which hired truck somebody is holding right now', function (): void {
        $driver = ($this->crew)('Marco Villanueva');

        $this->actingAs($this->admin)->postJson('/api/v1/vehicles', [
            'plate' => 'RPT 0001', 'model' => 'Fuso', 'registration_no' => 'LTO-RPT-1', 'capacity_kg' => 9000,
            'arrangement' => 'rented', 'rent_cents' => 4_000_000, 'driver_id' => $driver['id'],
        ])->assertCreated();

        $row = collect($this->actingAs($this->admin)->getJson('/api/v1/payroll/crew-report')->json('data'))
            ->firstWhere('driver_id', $driver['id']);

        expect($row['holding_plate'])->toBe('RPT 0001')
            ->and($row['holding_arrangement_label'])->toBe('Rented')
            ->and($row['holding_since'])->toBe(now()->toDateString());
    });

    it('is closed to anybody without payroll.view', function (): void {
        $driverUser = User::where('email', 'marco@cargorush.ph')->firstOrFail();

        $this->actingAs($driverUser)->getJson('/api/v1/payroll/crew-report')->assertForbidden();
    });
});

describe('one person’s trips per cutoff', function (): void {
    it('splits the trips into cutoffs and works the pay out from the rate', function (): void {
        $driver = ($this->crew)('Marco Villanueva');
        $helper = ($this->crew)('Pedro Santos', role: 'helper', rate: 50_000);

        ($this->haul)('2026-09-03', $driver['id'], [$helper['id']]);
        ($this->haul)('2026-09-10', $driver['id']);
        ($this->haul)('2026-09-20', $driver['id'], [$helper['id']]);

        $report = ($this->report)($driver['employee_id']);
        $cutoffs = collect($report['cutoffs'])->keyBy('start');

        expect($report['employee']['name'])->toBe('Marco Villanueva')
            ->and($cutoffs)->toHaveCount(2)
            ->and($cutoffs['2026-09-01']['counted_trips'])->toBe(2)
            ->and($cutoffs['2026-09-01']['expected_cents'])->toBe(300_000)
            ->and($cutoffs['2026-09-01']['trips'][0]['seat'])->toBe('driver')
            ->and($cutoffs['2026-09-01']['run'])->toBeNull()
            ->and($cutoffs['2026-09-16']['counted_trips'])->toBe(1)
            ->and($cutoffs['2026-09-16']['expected_cents'])->toBe(150_000)
            ->and($report['totals']['trips'])->toBe(3)
            ->and($report['totals']['expected_cents'])->toBe(450_000);

        $helperReport = ($this->report)($helper['employee_id']);

        expect($helperReport['totals']['trips'])->toBe(2)
            ->and($helperReport['totals']['expected_cents'])->toBe(100_000)
            ->and(collect($helperReport['cutoffs'])->keyBy('start')['2026-09-01']['trips'][0]['seat'])->toBe('helper');
    });

    it('marks the trips withheld while they held a hired truck, and says why', function (): void {
        $driver = ($this->crew)('Marco Villanueva');

        $truck = $this->actingAs($this->admin)->postJson('/api/v1/vehicles', [
            'plate' => 'RPT 0002', 'model' => 'Fuso', 'registration_no' => 'LTO-RPT-2', 'capacity_kg' => 9000,
            'arrangement' => 'rented', 'rent_cents' => 4_000_000,
        ])->assertCreated()->json('data');

        ($this->haul)('2026-09-03', $driver['id']);
        ($this->haul)('2026-09-08', $driver['id']);

        $this->travelTo(Carbon::parse('2026-09-06 09:00:00'), fn () => $this->actingAs($this->admin)
            ->patchJson("/api/v1/vehicles/{$truck['id']}", ['driver_id' => $driver['id']])->assertOk());

        $cutoff = collect(($this->report)($driver['employee_id'])['cutoffs'])->keyBy('start')['2026-09-01'];
        $trips = collect($cutoff['trips'])->keyBy('on');

        expect($cutoff['counted_trips'])->toBe(1)
            ->and($cutoff['withheld_trips'])->toBe(1)
            ->and($cutoff['expected_cents'])->toBe(150_000)
            ->and($trips['2026-09-03']['counted'])->toBeTrue()
            ->and($trips['2026-09-08']['counted'])->toBeFalse()
            ->and($trips['2026-09-08']['withheld_reason'])->toContain('RPT 0002')
            ->and($cutoff['hired_out'][0]['from'])->toBe('2026-09-06');
    });

    it('compares the rate with what the crew run actually paid', function (): void {
        $driver = ($this->crew)('Marco Villanueva');
        ($this->haul)('2026-09-03', $driver['id']);

        $run = $this->actingAs($this->admin)->postJson('/api/v1/payroll', [
            'scope' => 'crew', 'period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'pay_date' => '2026-09-15',
        ])->assertCreated()->json('data');

        $agreeing = collect(($this->report)($driver['employee_id'])['cutoffs'])->keyBy('start')['2026-09-01'];

        expect($agreeing['run']['reference'])->toBe($run['reference'])
            ->and($agreeing['run']['on_run'])->toBeTrue()
            ->and($agreeing['run']['basic_cents'])->toBe(150_000)
            ->and($agreeing['run']['agrees'])->toBeTrue()
            ->and($agreeing['trips'][0]['paid_on_run'])->toBe($run['reference']);

        ($this->haul)('2026-09-10', $driver['id']);

        $stale = collect(($this->report)($driver['employee_id'])['cutoffs'])->keyBy('start')['2026-09-01'];

        expect($stale['expected_cents'])->toBe(300_000)
            ->and($stale['run']['agrees'])->toBeFalse();
    });

    it('refuses an office employee, who has no trips to list', function (): void {
        $office = $this->actingAs($this->admin)->postJson('/api/v1/employees', [
            'first_name' => 'Elena', 'last_name' => 'Bautista', 'position' => 'Office Staff',
            'contact' => '0917 555 0199', 'hired_on' => '2026-01-05', 'amount_cents' => 3_000_000,
        ])->assertCreated()->json('data');

        $this->actingAs($this->admin)->getJson("/api/v1/payroll/crew-report/{$office['id']}")->assertNotFound();
    });
});
