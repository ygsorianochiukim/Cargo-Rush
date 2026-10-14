<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerVehicle;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Trucker\Services\WalletService;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();

    $this->user = User::factory()->create(['role' => Role::Trucker->value, 'company_id' => $this->company->getKey()]);
    $this->trucker = Trucker::factory()->approved()->create(['user_id' => $this->user->getKey()]);
    TruckerVehicle::factory()->create(['trucker_id' => $this->trucker->id, 'capacity_kg' => 20_000, 'status' => 'available']);

    $this->travelTo(now()->setDate(2026, 8, 12)->setTime(10, 0));

    $this->pencilled = fn (array $overrides = []): Trip => Trip::create([
        'customer_id' => $this->customer->getKey(), 'origin' => 'Malanang', 'destination' => 'Mambuaya',
        'cargo' => 'Goods', 'weight_kg' => 1_800, 'status' => StatusValue::Pending->value,
        'scheduled_at' => now()->addHour(), 'price_cents' => 1_000_000, 'currency' => 'PHP',
        'vehicle_id' => $this->vehicle->id,
        ...$overrides,
    ]);

    $this->partnerDelivers = function (Trip $trip): void {
        $this->actingAs($this->user)->postJson("/api/v1/partner/trips/{$trip->id}/start")->assertOk();
        $this->actingAs($this->user)
            ->postJson("/api/v1/partner/trips/{$trip->id}/deliver", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();
    };

    $this->fleetDelivered = fn (array $overrides = []): string => $this->actingAs($this->admin)
        ->postJson('/api/v1/trips', [
            'customer_id' => $this->customer->getKey(),
            'origin' => 'Manila', 'destination' => 'Batangas', 'cargo' => 'Dry goods', 'weight_kg' => 3_200,
            'driver_id' => $this->driver->id, 'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->subDay()->toIso8601String(),
            'price_cents' => 1_000_000,
            'status' => StatusValue::Delivered->value,
            ...$overrides,
        ])
        ->assertCreated()
        ->json('data.id');

    $this->quarter = fn () => $this->actingAs($this->admin)
        ->getJson('/api/v1/finance/summary?year=2026&quarter=q3')
        ->assertOk()
        ->json('data.totals');
});

describe('a trucker run', function (): void {
    it('counts only the ₱1,200 commission on a ₱10,000 run the desk handed to a trucker', function (): void {
        $trip = ($this->pencilled)();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/assign-trucker", ['trucker_id' => $this->trucker->id])
            ->assertOk();

        expect($trip->refresh()->vehicle_id)->toBeNull();

        ($this->partnerDelivers)($trip);

        expect(LedgerEntry::query()->sum('trip_income_cents'))->toEqual(0)
            ->and(($this->quarter)()['total_income_cents'])->toBe(120_000);
    });

    it('re-settles the trucker when a delivered run is repriced, until it has been paid out', function (): void {
        $trip = ($this->pencilled)();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/assign-trucker", ['trucker_id' => $this->trucker->id])
            ->assertOk();

        ($this->partnerDelivers)($trip);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$trip->id}", ['price_cents' => 500_000])
            ->assertOk();

        $entries = WalletEntry::query()->where('trip_id', $trip->id)->get();

        expect($entries)->toHaveCount(1)
            ->and($entries->first()->amount_cents)->toBe(440_000)
            ->and($trip->refresh()->commission_cents)->toBe(60_000);

        app(WalletService::class)->payOut($this->trucker, cleared: true);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$trip->id}", ['price_cents' => 800_000])
            ->assertStatus(422);

        expect($trip->refresh()->price_cents)->toBe(500_000);
    });

    it('books no fleet income for an older row that carries both a trucker and a unit', function (): void {
        $trip = ($this->pencilled)([
            'trucker_id' => $this->trucker->id,
            'booking_source' => 'cargo_rush',
            'status' => StatusValue::InTransit->value,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/complete", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();

        expect(LedgerEntry::query()->sum('trip_income_cents'))->toEqual(0)
            ->and(($this->quarter)()['total_income_cents'])->toBe(120_000);
    });

    it('drops the fleet unit when a trucker accepts a run a customer offered them', function (): void {
        $trip = ($this->pencilled)(['trucker_id' => $this->trucker->id]);

        $this->actingAs($this->user)->postJson("/api/v1/partner/jobs/{$trip->id}/accept")->assertCreated();

        expect($trip->refresh()->vehicle_id)->toBeNull();
    });

    it('refuses a fleet unit on a run a trucker is hauling', function (): void {
        $trip = ($this->pencilled)(['vehicle_id' => null, 'trucker_id' => $this->trucker->id]);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$trip->id}", ['vehicle_id' => $this->vehicle->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_id');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/confirm", [
                'driver_id' => $this->driver->id,
                'vehicle_id' => $this->vehicle->id,
                'scheduled_at' => now()->addHour()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vehicle_id');

        expect($trip->refresh()->vehicle_id)->toBeNull();
    });
});

describe('VAT', function (): void {
    it('books the net of VAT as income when prices are quoted all-in', function (): void {
        $this->company->update(['prices_include_vat' => true]);

        $id = ($this->fleetDelivered)();

        $income = (int) LedgerEntry::query()->where('trip_id', $id)->sum('trip_income_cents');
        $invoice = Invoice::query()->where('trip_id', $id)->firstOrFail();

        expect($income)->toBe(892_857)
            ->and($income)->toBe((int) $invoice->net_amount_cents)
            ->and($income + (int) $invoice->vat_cents)->toBe(1_000_000);
    });

    it('books the whole price as income when VAT is added on top', function (): void {
        $this->company->update(['prices_include_vat' => false]);

        $id = ($this->fleetDelivered)();

        expect((int) LedgerEntry::query()->where('trip_id', $id)->sum('trip_income_cents'))->toBe(1_000_000);
    });
});

describe('a billed run', function (): void {
    beforeEach(function (): void {
        $this->id = ($this->fleetDelivered)();
        $this->other = Vehicle::query()->whereKeyNot($this->vehicle->id)->firstOrFail();
    });

    it('takes it off the books when an unpaid run is moved off delivered', function (): void {
        $number = Invoice::query()->where('trip_id', $this->id)->value('number');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", ['status' => StatusValue::Assigned->value])
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::Assigned->value)
            ->assertJsonPath('data.billed_at', null)
            ->assertJsonPath('data.invoice', null);

        $invoice = Invoice::query()->where('trip_id', $this->id)->firstOrFail();

        expect($invoice->status)->toBe(StatusValue::Cancelled)
            ->and($invoice->number)->toBe($number)
            ->and((int) LedgerEntry::query()->sum('trip_income_cents'))->toBe(0)
            ->and(DeliveryLog::query()->where('trip_id', $this->id)->value('delivered_at'))->toBeNull();
    });

    it('bills the same invoice again when it is delivered a second time', function (): void {
        $number = Invoice::query()->where('trip_id', $this->id)->value('number');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", ['status' => StatusValue::Assigned->value])
            ->assertOk();

        $delivered = now()->subHours(2)->startOfMinute();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", [
                'status' => StatusValue::Delivered->value,
                'delivered_at' => $delivered->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::Delivered->value);

        $invoices = Invoice::query()->where('trip_id', $this->id)->get();

        expect($invoices)->toHaveCount(1)
            ->and($invoices->first()->number)->toBe($number)
            ->and($invoices->first()->status)->not->toBe(StatusValue::Cancelled)
            ->and($invoices->first()->issued_at->toDateString())->toBe($delivered->toDateString())
            ->and((int) LedgerEntry::query()->sum('trip_income_cents'))->toBe(1_000_000);
    });

    it('can cancel an unpaid run that was entered as delivered by mistake', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", ['status' => StatusValue::Cancelled->value])
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::Cancelled->value);

        expect(Invoice::query()->where('trip_id', $this->id)->firstOrFail()->status)->toBe(StatusValue::Cancelled);
    });

    it('keeps a paid run delivered', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", ['payment_status' => 'partial', 'paid_cents' => 100_000])
            ->assertOk();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", ['status' => StatusValue::Assigned->value])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'already has payments'));

        $trip = Trip::findOrFail($this->id);

        expect($trip->status)->toBe(StatusValue::Delivered)
            ->and($trip->isBilled())->toBeTrue()
            ->and((int) LedgerEntry::query()->sum('trip_income_cents'))->toBe(1_000_000);
    });

    it('re-books the income and the invoice when its price is corrected', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", ['price_cents' => 500_000])
            ->assertOk()
            ->assertJsonPath('data.price_cents', 500_000)
            ->assertJsonPath('data.invoice.amount_cents', fn (int $cents) => $cents >= 500_000);

        $invoice = Invoice::query()->where('trip_id', $this->id)->firstOrFail();

        expect((int) LedgerEntry::query()->where('trip_id', $this->id)->sum('trip_income_cents'))->toBe(500_000)
            ->and($invoice->net_amount_cents)->toBe(500_000)
            ->and(Invoice::query()->where('trip_id', $this->id)->count())->toBe(1)
            ->and(Trip::findOrFail($this->id)->pricing_source)->toBe('manual');
    });

    it('moves the income to the other unit when its unit is corrected', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", ['vehicle_id' => $this->other->id])
            ->assertOk();

        $old = Truck::query()->where('vehicle_id', $this->vehicle->id)->value('id');
        $new = Truck::query()->where('vehicle_id', $this->other->id)->value('id');

        expect((int) LedgerEntry::query()->where('truck_id', $old)->sum('trip_income_cents'))->toBe(0)
            ->and((int) LedgerEntry::query()->where('truck_id', $new)->sum('trip_income_cents'))->toBe(1_000_000);
    });

    it('re-bills the other customer when its customer is corrected', function (): void {
        $other = Customer::create(['name' => 'Pryce Gases', 'contact' => '0917 000 0000']);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", ['customer_id' => $other->getKey()])
            ->assertOk()
            ->assertJsonPath('data.customer_id', $other->getKey());

        expect(Invoice::query()->where('trip_id', $this->id)->firstOrFail()->customer_id)->toBe($other->getKey());
    });

    it('drops the unpaid invoice when its customer is taken off', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", ['customer_id' => null])
            ->assertOk();

        expect(Invoice::query()->where('trip_id', $this->id)->exists())->toBeFalse()
            ->and((int) LedgerEntry::query()->where('trip_id', $this->id)->sum('trip_income_cents'))->toBe(1_000_000);
    });

    it('refuses a price below what has already been paid', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", ['payment_status' => 'paid'])
            ->assertOk()
            ->assertJsonPath('data.invoice.status', StatusValue::Paid->value);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", ['price_cents' => 400_000])
            ->assertStatus(422);

        expect(Trip::findOrFail($this->id)->price_cents)->toBe(1_000_000)
            ->and((int) LedgerEntry::query()->where('trip_id', $this->id)->sum('trip_income_cents'))->toBe(1_000_000);
    });

    it('refuses to move a paid run to another customer', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", ['payment_status' => 'partial', 'paid_cents' => 100_000])
            ->assertOk()
            ->assertJsonPath('data.invoice.status', StatusValue::Partial->value);

        $other = Customer::create(['name' => 'Pryce Gases', 'contact' => '0917 000 0000']);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", ['customer_id' => $other->getKey()])
            ->assertStatus(422);

        expect(Trip::findOrFail($this->id)->customer_id)->toBe($this->customer->getKey());
    });

    it('deletes it while unpaid, cancelling its invoice and taking it off the books', function (): void {
        $this->actingAs($this->admin)->deleteJson("/api/v1/trips/{$this->id}")->assertSuccessful();

        expect(Trip::find($this->id))->toBeNull()
            ->and(Invoice::query()->where('trip_id', $this->id)->firstOrFail()->status)->toBe(StatusValue::Cancelled)
            ->and((int) LedgerEntry::query()->sum('trip_income_cents'))->toBe(0);
    });

    it('refuses to delete it once it has a payment', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", ['payment_status' => 'partial', 'paid_cents' => 100_000])
            ->assertOk();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/trips/{$this->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'cannot be deleted'));

        expect(Trip::find($this->id))->not->toBeNull()
            ->and((int) LedgerEntry::query()->sum('trip_income_cents'))->toBe(1_000_000);
    });

    it('still takes a corrected note, with the same unit and status sent back', function (): void {
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$this->id}", [
                'cargo' => 'Dry goods, 14 pallets',
                'vehicle_id' => $this->vehicle->id,
                'status' => StatusValue::Delivered->value,
                'price_cents' => 1_000_000,
            ])
            ->assertOk()
            ->assertJsonPath('data.cargo', 'Dry goods, 14 pallets');
    });
});
