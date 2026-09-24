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

/**
 * Who the fleet buys from, and what a service cost it.
 *
 * Two changes that are really one: a maintenance job used to record that a unit
 * was *due* an oil change and nothing else, so the ₱3,200 had to be filed as an
 * expense with a truck hung off it — which made the Other Expenses screen, a
 * list of meals and tarpaulins, also the fleet's service history. Neither was
 * findable in there.
 *
 * A job now carries its own cost and puts it where the rest of a unit's money
 * lives: the Maintenance column on that truck's daily sheet, which Profitability
 * and the Quarterly Summary have always counted per truck.
 *
 * The part worth the most tests is not the first save — it is the fourth. A
 * cost is corrected, a date is moved, somebody presses save twice, and every one
 * of those has to leave the sheet holding the figure exactly once.
 */
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

    /** Book a service, optionally already costed. */
    $this->book = fn (array $payload = []) => $this->actingAs($this->admin)
        ->postJson("/api/v1/vehicles/{$this->vehicle->id}/maintenance", [
            'kind' => 'Oil change',
            'due_at' => '2026-08-12',
            ...$payload,
        ]);

    /** Correct one. */
    $this->correct = fn (string $id, array $payload) => $this->actingAs($this->admin)
        ->patchJson("/api/v1/vehicles/{$this->vehicle->id}/maintenance/{$id}", $payload);

    /** What the unit's sheet says it cost in maintenance on a day. */
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

        // A job on the calendar has cost nothing yet. Null is "nobody has told
        // us", which is a different fact from a warranty job costing zero.
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

        // The day it was *done*, not the day it was due. A service booked for
        // the 12th and done on the 19th is the 19th's money.
        expect(($this->sheet)('2026-08-19'))->toBe(320_000)
            ->and(($this->sheet)('2026-08-12'))->toBe(0);
    });

    it('charges nothing for a job costed but never marked done', function (): void {
        ($this->book)(['cost_cents' => 320_000])->assertCreated();

        // There is no day to charge it to, and inventing one would put a figure
        // on a date nobody chose.
        expect(LedgerEntry::query()->count())->toBe(0);
    });

    it('opens no sheet row for a job that cost nothing', function (): void {
        // A warranty replacement. Real, and not a reason to conjure a line for
        // a day the unit may not have turned a wheel.
        ($this->book)(['cost_cents' => 0, 'completed_on' => '2026-08-19'])->assertCreated();

        expect(LedgerEntry::query()->count())->toBe(0);
    });
});

describe('correcting one', function (): void {
    it('moves the sheet by the difference, not by the whole figure again', function (): void {
        $id = ($this->book)(['cost_cents' => 320_000, 'completed_on' => '2026-08-19'])
            ->assertCreated()->json('data.id');

        ($this->correct)($id, ['cost_cents' => 350_000])->assertOk();

        // 320,000 then +30,000. Charging the full 350,000 again would leave the
        // unit carrying 670,000 for one oil change, and nothing on the sheet
        // would say where it came from.
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

        // A moved date is two rows, not a difference on one. Leaving the old
        // day holding it would overstate that day for ever.
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

        // Resolved off the unit in the path rather than on its own, so an id
        // from elsewhere is a 404 instead of somebody else's record.
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

        // The whole point of moving it out of Other Expenses: a service is what
        // *that unit* cost, and it lands in the unit's own column rather than
        // in a period total that says nothing about which truck.
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

        // Scoped to the company, like every other unique in this system: two
        // fleets buying from one shop each keep their own record of it.
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

        // Kept apart rather than merged, because what kind of spend it was is
        // what tells a garage from a chandler.
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
