<?php

declare(strict_types=1);

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * A run with more than one helper on it.
 *
 * A heavy load goes out with two or three people to lift it, and a single
 * `helper_id` could only name one of them — the others were left off the
 * record, and so off the sheet and the payslip. These pin the list end to end:
 * the desk names the crew, the delivery carries it onto the day's sheet, each
 * helper's pay is its own line, and payroll counts every one of them.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();

    $this->person = fn (string $name, string $licence) => Driver::create([
        'name' => $name, 'licence_no' => $licence, 'licence_expiry' => '2029-08-31', 'status' => 'available',
    ]);

    $this->ana = ($this->person)('Ana Lim', 'H01-00-000001');
    $this->ben = ($this->person)('Ben Cruz', 'H01-00-000002');
    $this->cai = ($this->person)('Cai Santos', 'H01-00-000003');

    $this->book = fn (array $overrides = []) => $this->actingAs($this->admin)->postJson('/api/v1/trips', [
        'origin' => 'Manila',
        'destination' => 'Batangas',
        'cargo' => 'Cement, 200 bags',
        'weight_kg' => 3200,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'scheduled_at' => now()->toIso8601String(),
        'status' => StatusValue::Assigned->value,
        ...$overrides,
    ]);
});

describe('the desk naming the crew', function (): void {
    it('books a run with several helpers, in the order they were named', function (): void {
        $trip = ($this->book)(['helper_ids' => [$this->ben->id, $this->ana->id]])
            ->assertCreated()
            ->json('data');

        expect($trip['helper_ids'])->toBe([$this->ben->id, $this->ana->id])
            ->and(array_column($trip['helpers'], 'name'))->toBe(['Ben Cruz', 'Ana Lim']);
    });

    it('replaces the crew when it is named again, and leaves it alone when it is not', function (): void {
        $id = ($this->book)(['helper_ids' => [$this->ana->id, $this->ben->id]])->json('data.id');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$id}", ['helper_ids' => [$this->cai->id]])
            ->assertOk()
            ->assertJsonPath('data.helper_ids', [$this->cai->id]);

        // A PATCH about something else keeps whoever is on it.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$id}", ['cargo' => 'Cement, 180 bags'])
            ->assertOk()
            ->assertJsonPath('data.helper_ids', [$this->cai->id]);

        // And an empty list takes everyone off.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$id}", ['helper_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.helper_ids', []);
    });

    it('refuses the same helper twice, and the driver as a helper', function (): void {
        ($this->book)(['helper_ids' => [$this->ana->id, $this->ana->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('helper_ids.0');

        ($this->book)(['helper_ids' => [$this->ana->id, $this->driver->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('helper_ids.1');
    });

    it('lets a helper see the run in their history, whichever helper they were', function (): void {
        $id = ($this->book)(['helper_ids' => [$this->ana->id, $this->ben->id]])->json('data.id');

        $this->actingAs($this->admin)
            ->getJson("/api/v1/trips?driver_id={$this->ben->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);
    });
});

describe('the delivery carrying the crew onto the sheet', function (): void {
    it('opens the day with a line for every helper, at no pay yet', function (): void {
        $id = ($this->book)(['helper_ids' => [$this->ana->id, $this->ben->id]])->json('data.id');

        $this->passPreTripCheck($id);
        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertOk();
        $this->actingAs($this->marco)
            ->postJson('/api/v1/trips/current/deliver', ['receiver_name' => 'R. Uy'])
            ->assertOk();

        $row = LedgerEntry::where('trip_id', $id)->firstOrFail();

        expect($row->helpers->pluck('driver_id')->all())->toBe([$this->ana->id, $this->ben->id])
            ->and($row->helpers->pluck('salary_cents')->all())->toBe([0, 0])
            ->and($row->helper_salary_cents)->toBe(0);

        // Its delivery log names the whole crew.
        $this->actingAs($this->admin)
            ->getJson('/api/v1/delivery-logs/'.DeliveryLog::where('trip_id', $id)->value('id'))
            ->assertJsonPath('data.helper_names', ['Ana Lim', 'Ben Cruz']);
    });
});

describe('each helper paid their own figure on the sheet', function (): void {
    beforeEach(function (): void {
        $this->truck = Truck::create(['label' => 'Truck 1', 'plate' => 'NCR 4412', 'position' => 1]);

        $this->log = fn (array $payload) => $this->actingAs($this->admin)->postJson('/api/v1/ledger', [
            'truck_id' => $this->truck->id,
            'date' => '2026-09-03',
            'driver_id' => $this->driver->id,
            'driver_salary_cents' => 120_000,
            ...$payload,
        ]);
    });

    it('keeps each helper’s pay, and totals them into the day', function (): void {
        $row = ($this->log)(['helpers' => [
            ['driver_id' => $this->ana->id, 'salary_cents' => 60_000],
            ['driver_id' => $this->ben->id, 'salary_cents' => 45_000],
        ]])->assertCreated()->json('data');

        expect($row['helper_salary_cents'])->toBe(105_000)
            ->and($row['total_expenses_cents'])->toBe(120_000 + 105_000)
            ->and($row['helpers'])->toBe([
                ['driver_id' => $this->ana->id, 'name' => 'Ana Lim', 'salary_cents' => 60_000],
                ['driver_id' => $this->ben->id, 'name' => 'Ben Cruz', 'salary_cents' => 45_000],
            ]);
    });

    it('replaces the lines on an edit, and moves the total with them', function (): void {
        $id = ($this->log)(['helpers' => [
            ['driver_id' => $this->ana->id, 'salary_cents' => 60_000],
            ['driver_id' => $this->ben->id, 'salary_cents' => 45_000],
        ]])->json('data.id');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/ledger/{$id}", ['helpers' => [
                ['driver_id' => $this->cai->id, 'salary_cents' => 50_000],
            ]])
            ->assertOk()
            ->assertJsonPath('data.helper_salary_cents', 50_000)
            ->assertJsonPath('data.helpers.0.name', 'Cai Santos');
    });

    it('refuses the same helper on one day twice', function (): void {
        ($this->log)(['helpers' => [
            ['driver_id' => $this->ana->id, 'salary_cents' => 60_000],
            ['driver_id' => $this->ana->id, 'salary_cents' => 60_000],
        ]])->assertStatus(422)->assertJsonValidationErrors('helpers');
    });

    it('shows each helper as their own row behind Total expenses', function (): void {
        ($this->log)(['date' => '2026-08-03', 'helpers' => [
            ['driver_id' => $this->ana->id, 'salary_cents' => 60_000],
            ['driver_id' => $this->ben->id, 'salary_cents' => 45_000],
        ]])->assertCreated();

        $report = $this->actingAs($this->admin)
            ->getJson('/api/v1/finance/expense-lines?from=2026-07-01&to=2026-09-30')
            ->json('data');

        $helpers = array_values(array_filter($report['lines'], static fn ($l) => $l['kind'] === 'Helper salary'));

        expect(array_column($helpers, 'description'))->toEqualCanonicalizing(['Ana Lim', 'Ben Cruz'])
            ->and($report['total_cents'])->toBe(
                $this->actingAs($this->admin)
                    ->getJson('/api/v1/finance/summary?year=2026&quarter=q3')
                    ->json('data.totals.total_expenses_cents'),
            );
    });

    describe('from an app that only sends one helper figure', function (): void {
        it('files it as unnamed helper pay on a new day', function (): void {
            $row = ($this->log)(['helper_salary_cents' => 50_000])->assertCreated()->json('data');

            expect($row['helper_salary_cents'])->toBe(50_000)
                ->and($row['helpers'])->toBe([['driver_id' => null, 'name' => null, 'salary_cents' => 50_000]]);
        });

        it('gives it to the one helper a day already names', function (): void {
            $id = ($this->log)(['helpers' => [['driver_id' => $this->ana->id, 'salary_cents' => 0]]])->json('data.id');

            $this->actingAs($this->admin)
                ->patchJson("/api/v1/ledger/{$id}", ['helper_salary_cents' => 55_000])
                ->assertOk()
                ->assertJsonPath('data.helpers.0.driver_id', $this->ana->id)
                ->assertJsonPath('data.helpers.0.salary_cents', 55_000);
        });

        it('refuses it for a day with several helpers rather than inventing a split', function (): void {
            $id = ($this->log)(['helpers' => [
                ['driver_id' => $this->ana->id, 'salary_cents' => 60_000],
                ['driver_id' => $this->ben->id, 'salary_cents' => 45_000],
            ]])->json('data.id');

            $this->actingAs($this->admin)
                ->patchJson("/api/v1/ledger/{$id}", ['helper_salary_cents' => 90_000])
                ->assertStatus(422)
                ->assertJsonValidationErrors('helper_salary_cents');

            expect(LedgerEntry::findOrFail($id)->helper_salary_cents)->toBe(105_000);
        });
    });
});

describe('payroll counting every helper', function (): void {
    beforeEach(function (): void {
        $this->hire = function (Driver $driver, string $no, string $basis, int $rate): void {
            $employee = Employee::create([
                'employee_no' => $no, 'first_name' => explode(' ', $driver->name)[0],
                'last_name' => explode(' ', $driver->name)[1], 'position' => 'Helper',
                'contact' => '0917 555 0123', 'hired_on' => '2026-01-05', 'status' => 'active',
                'driver_id' => $driver->id,
            ]);

            payContract($employee, $basis, $rate);
        };

        $this->open = fn () => collect($this->actingAs($this->admin)->postJson('/api/v1/payroll', [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'pay_date' => '2026-09-15',
        ])->assertCreated()->json('data.lines'))->keyBy('employee_no');
    });

    it('pays each helper on a run for the run, per trip', function (): void {
        ($this->hire)($this->ana, 'EMP-H1', 'per_trip', 50_000);
        ($this->hire)($this->ben, 'EMP-H2', 'per_trip', 50_000);

        Trip::create([
            'reference' => 'TRIP-H-1', 'origin' => 'Davao City', 'destination' => 'Tagum City',
            'cargo' => 'Cement', 'weight_kg' => 4000, 'driver_id' => $this->driver->id,
            'status' => 'delivered', 'scheduled_at' => '2026-09-03 07:00:00',
        ])->setHelpers([$this->ana->id, $this->ben->id]);

        $lines = ($this->open)();

        expect($lines['EMP-H1']['trips'])->toBe(1)
            ->and($lines['EMP-H2']['trips'])->toBe(1)
            ->and($lines['EMP-H2']['basic_cents'])->toBe(50_000);
    });

    it('gives each helper on a sheet day the day, on a daily rate', function (): void {
        ($this->hire)($this->ana, 'EMP-H1', 'daily', 60_000);
        ($this->hire)($this->ben, 'EMP-H2', 'daily', 60_000);

        $truck = Truck::create(['label' => 'Truck 9', 'plate' => 'NCR 9999', 'position' => 9]);

        $this->actingAs($this->admin)->postJson('/api/v1/ledger', [
            'truck_id' => $truck->id,
            'date' => '2026-09-04',
            'helpers' => [
                ['driver_id' => $this->ana->id, 'salary_cents' => 60_000],
                ['driver_id' => $this->ben->id, 'salary_cents' => 60_000],
            ],
        ])->assertCreated();

        $lines = ($this->open)();

        expect($lines['EMP-H1']['days_worked'])->toBe(1)
            ->and($lines['EMP-H2']['days_worked'])->toBe(1)
            ->and($lines['EMP-H2']['basic_cents'])->toBe(60_000);
    });
});
