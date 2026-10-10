<?php

declare(strict_types=1);

use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(NavigationSeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::factory()->create(['role' => Role::Administrator]);
    $this->accountant = User::factory()->create(['role' => Role::Accountant]);

    $this->unit = fn (string $plate) => Vehicle::create([
        'plate' => $plate,
        'model' => 'Isuzu Forward',
        'registration_no' => 'REG-'.preg_replace('/\D/', '', $plate),
        'capacity_kg' => 15_000,
        'status' => StatusValue::Available->value,
        'odometer_km' => 90_000,
    ]);

    $this->truck = ($this->unit)('ABC 1234');
    $this->spare = ($this->unit)('XYZ 9999');
    $this->garage = Supplier::factory()->create(['name' => 'Davao Lubes & Parts']);

    $this->file = fn (array $payload = [], ?User $as = null) => $this->actingAs($as ?? $this->accountant)
        ->postJson('/api/v1/maintenance', [
            'vehicle_id' => $this->truck->id,
            'kind' => 'Oil change',
            'due_at' => '2026-08-12',
            ...$payload,
        ]);

    $this->sheet = function (Vehicle $vehicle, string $date): int {
        $truck = Truck::query()->where('vehicle_id', $vehicle->id)->first();

        if ($truck === null) {
            return 0;
        }

        return (int) LedgerEntry::query()
            ->where('truck_id', $truck->id)
            ->whereDate('date', $date)
            ->value('maintenance_cents');
    };
});

describe('the fleet list', function (): void {
    it('shows servicing across every unit, with the plate and the garage on it', function (): void {
        ($this->file)(['cost_cents' => 320_000, 'completed_on' => '2026-08-19', 'supplier_id' => $this->garage->id]);
        ($this->file)(['vehicle_id' => $this->spare->id, 'kind' => 'Two front tyres', 'cost_cents' => 1_760_000, 'completed_on' => '2026-08-20']);

        $response = $this->actingAs($this->accountant)->getJson('/api/v1/maintenance')->assertOk();

        $rows = collect($response->json('data'))->keyBy('kind');

        expect($rows)->toHaveCount(2)
            ->and($rows['Oil change']['vehicle_plate'])->toBe('ABC 1234')
            ->and($rows['Oil change']['supplier_name'])->toBe('Davao Lubes & Parts')
            ->and($rows['Two front tyres']['vehicle_plate'])->toBe('XYZ 9999');

        expect($response->json('meta.costed_total_cents'))->toBe(2_080_000);
    });

    it('sorts what is still booked above what is already done', function (): void {
        ($this->file)(['kind' => 'Done last week', 'cost_cents' => 100_000, 'completed_on' => '2026-08-19']);
        ($this->file)(['kind' => 'Booked for next month', 'due_at' => '2026-09-30']);

        $kinds = collect($this->actingAs($this->accountant)->getJson('/api/v1/maintenance')->json('data'))
            ->pluck('kind')
            ->all();

        expect($kinds)->toBe(['Booked for next month', 'Done last week']);
    });

    it('narrows to one unit, one garage, or only what has been costed', function (): void {
        ($this->file)(['cost_cents' => 320_000, 'completed_on' => '2026-08-19', 'supplier_id' => $this->garage->id]);
        ($this->file)(['vehicle_id' => $this->spare->id, 'kind' => 'Brake pads']);

        $byUnit = $this->actingAs($this->accountant)
            ->getJson("/api/v1/maintenance?vehicle_id={$this->spare->id}")->json('data');

        $byGarage = $this->actingAs($this->accountant)
            ->getJson("/api/v1/maintenance?supplier_id={$this->garage->id}")->json('data');

        $costed = $this->actingAs($this->accountant)
            ->getJson('/api/v1/maintenance?costed=1')->json('data');

        expect($byUnit)->toHaveCount(1)
            ->and($byUnit[0]['kind'])->toBe('Brake pads')
            ->and($byGarage)->toHaveCount(1)
            ->and($byGarage[0]['kind'])->toBe('Oil change')
            ->and($costed)->toHaveCount(1)
            ->and($costed[0]['cost_cents'])->toBe(320_000);
    });

    it('windows on the day the job belongs to, done or merely due', function (): void {
        ($this->file)(['kind' => 'Done in August', 'cost_cents' => 100_000, 'completed_on' => '2026-08-19', 'due_at' => '2026-07-01']);
        ($this->file)(['kind' => 'Due in September', 'due_at' => '2026-09-15']);

        $august = collect($this->actingAs($this->accountant)
            ->getJson('/api/v1/maintenance?from=2026-08-01&to=2026-08-31')->json('data'))
            ->pluck('kind')->all();

        expect($august)->toBe(['Done in August']);
    });
});

describe('filing one from the office', function (): void {
    it('charges it to the unit named in the payload', function (): void {
        ($this->file)([
            'cost_cents' => 320_000,
            'completed_on' => '2026-08-19',
            'supplier_id' => $this->garage->id,
        ])->assertCreated();

        expect(($this->sheet)($this->truck, '2026-08-19'))->toBe(320_000)
            ->and(($this->sheet)($this->spare, '2026-08-19'))->toBe(0);
    });

    it('insists on being told which truck', function (): void {
        $this->actingAs($this->accountant)
            ->postJson('/api/v1/maintenance', ['kind' => 'Oil change', 'due_at' => '2026-08-12'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_id');
    });

    it('lets whoever files the fleet spend file a garage bill', function (): void {
        ($this->file)([], $this->accountant)->assertCreated();
        ($this->file)(['kind' => 'Coolant flush'], $this->admin)->assertCreated();
    });

    it('is closed to somebody who files neither spend nor units', function (): void {
        $driver = User::factory()->create(['role' => Role::Driver]);

        ($this->file)([], $driver)->assertForbidden();
    });

    it('lets them read the fleet, because the form has to ask which truck', function (): void {
        $plates = collect($this->actingAs($this->accountant)
            ->getJson('/api/v1/vehicles')->assertOk()->json('data'))
            ->pluck('plate');

        expect($plates)->toContain('ABC 1234', 'XYZ 9999');
    });
});

describe('correcting one from the office', function (): void {
    it('moves the sheet by the difference, as the unit screen does', function (): void {
        $id = ($this->file)(['cost_cents' => 320_000, 'completed_on' => '2026-08-19'])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->accountant)
            ->patchJson("/api/v1/maintenance/{$id}", ['cost_cents' => 350_000])
            ->assertOk();

        expect(($this->sheet)($this->truck, '2026-08-19'))->toBe(350_000);
    });

    it('moves a job keyed against the wrong plate, and the money with it', function (): void {
        $id = ($this->file)(['cost_cents' => 320_000, 'completed_on' => '2026-08-19'])
            ->assertCreated()->json('data.id');

        $moved = $this->actingAs($this->accountant)
            ->patchJson("/api/v1/maintenance/{$id}", ['vehicle_id' => $this->spare->id])
            ->assertOk()->json('data');

        expect($moved['vehicle_plate'])->toBe('XYZ 9999')
            ->and(($this->sheet)($this->truck, '2026-08-19'))->toBe(0)
            ->and(($this->sheet)($this->spare, '2026-08-19'))->toBe(320_000);
    });

    it('credits the unit back when the job is removed', function (): void {
        $id = ($this->file)(['cost_cents' => 320_000, 'completed_on' => '2026-08-19'])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/maintenance/{$id}")
            ->assertNoContent();

        expect(($this->sheet)($this->truck, '2026-08-19'))->toBe(0);
    });
});

it('is on the sidebar beside the rest of the spend', function (): void {
    $nav = collect($this->actingAs($this->accountant)->getJson('/api/v1/navigation')->json('data'))
        ->firstWhere('key', 'maintenance');

    expect($nav)->not->toBeNull()
        ->and($nav['label'])->toBe('Truck Maintenance')
        ->and($nav['group'])->toBe('Sales & Billing');
});
