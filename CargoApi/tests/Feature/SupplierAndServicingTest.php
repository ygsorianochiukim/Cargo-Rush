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

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => Role::Administrator->value,
    ]);

    $this->vehicle = Vehicle::create([
        'plate' => 'ABC 1234',
        'model' => 'Isuzu Forward',
        'registration_no' => 'REG-0001',
        'capacity_kg' => 15_000,
        'status' => StatusValue::Available->value,
        'odometer_km' => 90_000,
    ]);

    $this->garage = Supplier::factory()->create(['name' => 'Davao Lubes & Parts']);

    $this->book = fn (array $payload = []) => $this->actingAs($this->admin)
        ->postJson("/api/v1/vehicles/{$this->vehicle->id}/maintenance", [
            'kind' => 'Oil change',
            'due_at' => '2026-08-12',
            ...$payload,
        ]);

    $this->correct = fn (string $id, array $payload) => $this->actingAs($this->admin)
        ->patchJson("/api/v1/vehicles/{$this->vehicle->id}/maintenance/{$id}", $payload);

    $this->sheet = function (string $date): int {
        $truck = Truck::query()->where('vehicle_id', $this->vehicle->id)->first();

        if ($truck === null) {
            return 0;
        }

        return (int) LedgerEntry::query()
            ->where('truck_id', $truck->id)
            ->whereDate('date', $date)
            ->value('maintenance_cents');
    };
});

describe('booking a service', function (): void {
    it('records what is due without charging anybody for it', function (): void {
        $job = ($this->book)()->assertCreated()->json('data');

        expect($job['cost_cents'])->toBeNull()
            ->and($job['on_the_sheet'])->toBeFalse()
            ->and(LedgerEntry::query()->count())->toBe(0);
    });

    it('puts a costed job on the unit own sheet, on the day it was done', function (): void {
        ($this->book)([
            'cost_cents' => 320_000,
            'completed_on' => '2026-08-19',
            'supplier_id' => $this->garage->id,
        ])->assertCreated();

        expect(($this->sheet)('2026-08-19'))->toBe(320_000)
            ->and(($this->sheet)('2026-08-12'))->toBe(0);
    });

    it('charges nothing for a job costed but never marked done', function (): void {
        ($this->book)(['cost_cents' => 320_000])->assertCreated();

        expect(LedgerEntry::query()->count())->toBe(0);
    });

    it('opens no sheet row for a job that cost nothing', function (): void {
        ($this->book)(['cost_cents' => 0, 'completed_on' => '2026-08-19'])->assertCreated();

        expect(LedgerEntry::query()->count())->toBe(0);
    });
});

describe('correcting one', function (): void {
    it('moves the sheet by the difference, not by the whole figure again', function (): void {
        $id = ($this->book)(['cost_cents' => 320_000, 'completed_on' => '2026-08-19'])
            ->assertCreated()->json('data.id');

        ($this->correct)($id, ['cost_cents' => 350_000])->assertOk();

        expect(($this->sheet)('2026-08-19'))->toBe(350_000);
    });

    it('does nothing at all when the same job is saved twice', function (): void {
        $id = ($this->book)(['cost_cents' => 320_000, 'completed_on' => '2026-08-19'])
            ->assertCreated()->json('data.id');

        ($this->correct)($id, ['note' => 'Receipt filed'])->assertOk();
        ($this->correct)($id, ['note' => 'Receipt filed again'])->assertOk();

        expect(($this->sheet)('2026-08-19'))->toBe(320_000);
    });

    it('takes the figure off the old day when the date moves', function (): void {
        $id = ($this->book)(['cost_cents' => 320_000, 'completed_on' => '2026-08-19'])
            ->assertCreated()->json('data.id');

        ($this->correct)($id, ['completed_on' => '2026-08-21'])->assertOk();

        expect(($this->sheet)('2026-08-19'))->toBe(0)
            ->and(($this->sheet)('2026-08-21'))->toBe(320_000);
    });

    it('credits it back when the cost is cleared', function (): void {
        $id = ($this->book)(['cost_cents' => 320_000, 'completed_on' => '2026-08-19'])
            ->assertCreated()->json('data.id');

        $job = ($this->correct)($id, ['cost_cents' => null])->assertOk()->json('data');

        expect(($this->sheet)('2026-08-19'))->toBe(0)
            ->and($job['on_the_sheet'])->toBeFalse();
    });

    it('credits it back when the job is deleted', function (): void {
        $id = ($this->book)(['cost_cents' => 320_000, 'completed_on' => '2026-08-19'])
            ->assertCreated()->json('data.id');

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/vehicles/{$this->vehicle->id}/maintenance/{$id}")
            ->assertNoContent();

        expect(($this->sheet)('2026-08-19'))->toBe(0);
    });

    it('will not edit a job belonging to another unit', function (): void {
        $other = Vehicle::create([
            'plate' => 'XYZ 9999',
            'model' => 'Fuso Canter',
            'registration_no' => 'REG-0002',
            'capacity_kg' => 8_000,
            'status' => StatusValue::Available->value,
        ]);

        $id = ($this->book)()->assertCreated()->json('data.id');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/vehicles/{$other->id}/maintenance/{$id}", ['cost_cents' => 100])
            ->assertNotFound();
    });

    it('refuses a figure that is somebody typing pesos into a centavos field', function (): void {
        ($this->book)(['cost_cents' => 99_000_000, 'completed_on' => '2026-08-19'])
            ->assertStatus(422);
    });
});

describe('what it reaches', function (): void {
    it('shows up as that unit maintenance, not as overhead', function (): void {
        ($this->book)(['cost_cents' => 320_000, 'completed_on' => '2026-08-19'])->assertCreated();

        $totals = $this->actingAs($this->admin)
            ->getJson('/api/v1/finance/profitability?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->json('data');

        $row = collect($totals['trucks'])->firstWhere('truck.plate', 'ABC 1234');

        expect($row['maintenance_cents'])->toBe(320_000)
            ->and($row['total_expenses_cents'])->toBe(320_000)
            ->and($totals['totals']['overhead_cents'])->toBe(0);
    });
});

describe('the supplier record', function (): void {
    it('refuses a second supplier by the same name', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/suppliers', ['name' => 'Davao Lubes & Parts'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'You already buy from somebody by that name.');
    });

    it('lets another haulier buy from the same garage', function (): void {
        $rival = $this->makeCompany('Rival Freight');

        $theirs = $this->asCompany($rival, fn () => Supplier::factory()->create([
            'name' => 'Davao Lubes & Parts',
        ]));

        expect($theirs->name)->toBe('Davao Lubes & Parts')
            ->and($theirs->company_id)->toBe($rival->getKey());
    });

    it('adds up the three places its money shows up', function (): void {
        ($this->book)([
            'cost_cents' => 320_000,
            'completed_on' => '2026-08-19',
            'supplier_id' => $this->garage->id,
        ])->assertCreated();

        $row = collect(
            $this->actingAs($this->admin)->getJson('/api/v1/suppliers')->assertOk()->json('data')
        )->firstWhere('id', $this->garage->id);

        expect($row['service_spend_cents'])->toBe(320_000)
            ->and($row['expense_spend_cents'])->toBe(0)
            ->and($row['spend_cents'])->toBe(320_000);
    });

    it('lists what has been bought from one', function (): void {
        ($this->book)([
            'cost_cents' => 320_000,
            'completed_on' => '2026-08-19',
            'supplier_id' => $this->garage->id,
        ])->assertCreated();

        $history = $this->actingAs($this->admin)
            ->getJson("/api/v1/suppliers/{$this->garage->id}/history")
            ->assertOk()
            ->json('data');

        expect($history['maintenance'])->toHaveCount(1)
            ->and($history['expenses'])->toBeEmpty()
            ->and($history['bills'])->toBeEmpty();
    });

    it('keeps an account nobody can manage out of the writing', function (): void {
        $dispatcher = User::create([
            'name' => 'Dispatcher', 'email' => 'dispatch@test.test',
            'password' => 'password', 'role' => Role::Dispatcher->value,
        ]);

        $this->actingAs($dispatcher)
            ->postJson('/api/v1/suppliers', ['name' => 'Somebody Else'])
            ->assertForbidden();
    });
});
