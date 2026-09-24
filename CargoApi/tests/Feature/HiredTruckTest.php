<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Services\TruckRentService;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * Trucks the fleet does not own, and the three ways they are paid for.
 *
 * All four arrangements **dispatch identically** — same board, same driver,
 * same proof of delivery, same invoice to the customer. What differs is only
 * where the money goes when the run closes, and that is all these tests are
 * about:
 *
 *   `owned`          every peso is the fleet's
 *   `rented`         a flat ₱50,000 a month, and every peso is still the fleet's
 *   `rented_share`   no rent; the fleet keeps 15% and the owner takes the rest
 *   `subcontracted`  the same at 12%, to somebody holding the handset
 *
 * The two share arrangements post to a partner's **wallet**, which is the same
 * account a trucker's own work accrues in — because to the person being paid it
 * is the same thing, money the fleet is holding for them, and one account that
 * nets both is the point of having a wallet rather than two reports.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
    $this->driver = Driver::query()->firstOrFail();

    /** Somebody the fleet pays for a truck. No licence: they do not drive it. */
    $this->owner = fn (string $name) => Trucker::factory()->approved()->create([
        'name' => $name,
        'licence_no' => null,
    ]);

    /** A unit on the fleet, on whatever terms. */
    $this->truck = fn (array $terms = []) => Vehicle::create([
        'plate' => 'HIRE-'.fake()->unique()->numberBetween(1000, 9999),
        'model' => 'Isuzu Forward',
        'registration_no' => fake()->unique()->bothify('REG-####'),
        'capacity_kg' => 15_000,
        'status' => StatusValue::Available->value,
        ...$terms,
    ]);

    /** Book it, run it, close it out — the whole of a delivery. */
    $this->haul = function (Vehicle $vehicle, int $priceCents = 1_000_000): Trip {
        $trip = Trip::create([
            'customer_id' => $this->customer->getKey(),
            'origin' => 'Iponan',
            'destination' => 'Bukidnon',
            'cargo' => 'Rice',
            'weight_kg' => 10_000,
            'status' => StatusValue::Assigned->value,
            'scheduled_at' => now()->addDay(),
            'price_cents' => $priceCents,
            'currency' => 'PHP',
            'vehicle_id' => $vehicle->getKey(),
            'driver_id' => $this->driver->getKey(),
        ]);

        $this->passPreTripCheck($trip->getKey());

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/complete", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();

        return $trip->refresh();
    };
});

describe('an owned truck', function (): void {
    it('keeps every peso, as it always did', function (): void {
        $truck = ($this->truck)();
        $trip = ($this->haul)($truck);

        expect($truck->terms())->toBe(VehicleArrangement::Owned)
            ->and($truck->sharesRevenue())->toBeFalse();

        // Nobody outside the fleet is owed anything.
        expect(WalletEntry::query()->count())->toBe(0);

        $sheet = LedgerEntry::query()->where('trip_id', $trip->getKey())->firstOrFail();

        expect($sheet->trip_income_cents)->toBe(1_000_000)
            ->and($sheet->owner_share_cents)->toBe(0);
    });
});

describe('a truck rented at a flat fee', function (): void {
    beforeEach(function (): void {
        $this->rented = ($this->truck)([
            'arrangement' => VehicleArrangement::Rented->value,
            'owner_name' => 'Delfin Hauling',
            'rent_cents' => 5_000_000,
        ]);
    });

    it('still keeps every peso the truck earns', function (): void {
        // The fleet paid for the month up front, so the income is all its own
        // — the difference from a share arrangement, and the risk it took.
        $trip = ($this->haul)($this->rented);

        expect(WalletEntry::query()->count())->toBe(0);

        expect(LedgerEntry::query()->where('trip_id', $trip->getKey())->firstOrFail()->owner_share_cents)
            ->toBe(0);
    });

    it('bills the rent for the month, against that truck', function (): void {
        ($this->haul)($this->rented);

        $raised = app(TruckRentService::class)->chargeMonth(now());

        expect($raised)->toBe(1);

        $rent = Expense::query()->where('vehicle_id', $this->rented->getKey())->firstOrFail();

        expect($rent->amount_cents)->toBe(5_000_000)
            ->and($rent->payee)->toBe('Delfin Hauling')
            // Raised, not paid. Whoever settles it says so, in the same place
            // every other bill is settled.
            ->and($rent->status)->toBe(StatusValue::Pending)
            // Attributed to the unit's sheet, so it lands on that truck's
            // profitability rather than in fleet overhead.
            ->and($rent->truck_id)->not->toBeNull();
    });

    it('does not bill the same month twice', function (): void {
        // The natural fix for a missed cron is to run it by hand, and nobody
        // should have to check first whether that double-bills.
        expect(app(TruckRentService::class)->chargeMonth(now()))->toBe(1);
        expect(app(TruckRentService::class)->chargeMonth(now()))->toBe(0);

        expect(Expense::query()->where('vehicle_id', $this->rented->getKey())->count())->toBe(1);
    });

    it('bills a truck with no rent recorded nothing at all', function (): void {
        // A rented unit nobody put terms on is a gap to see, not a ₱0 charge
        // to file.
        $this->rented->update(['rent_cents' => null]);

        expect(app(TruckRentService::class)->chargeMonth(now()))->toBe(0);
    });

    it('charges rent whether the truck worked or not', function (): void {
        // The whole difference from a share arrangement. An idle month still
        // costs, which is the risk the fleet took in hiring one.
        expect(app(TruckRentService::class)->chargeMonth(now()))->toBe(1);
    });
});

describe('a ten-wheeler rented on a share', function (): void {
    beforeEach(function (): void {
        $this->owner = ($this->owner)('Delfin Uy');

        $this->shared = ($this->truck)([
            'arrangement' => VehicleArrangement::RentedShare->value,
            'wheels' => 10,
            'owner_name' => 'Delfin Uy',
            'owner_trucker_id' => $this->owner->getKey(),
            'share_bp' => 1500,
        ]);
    });

    it('keeps 15% and credits the owner the rest', function (): void {
        $trip = ($this->haul)($this->shared, 1_000_000);

        $entry = WalletEntry::query()->where('trip_id', $trip->getKey())->firstOrFail();

        // ₱10,000 billed, the fleet keeps ₱1,500, the owner is credited ₱8,500.
        expect($entry->kind)->toBe(WalletEntryKind::Earning)
            ->and($entry->amount_cents)->toBe(850_000)
            ->and($entry->rate_bp)->toBe(1500)
            ->and($entry->gross_cents)->toBe(1_000_000)
            ->and($entry->trucker_id)->toBe($this->owner->getKey());
    });

    it('charges no monthly rent', function (): void {
        // The mirror of a flat rental: here the owner carries the risk of a
        // quiet month, so there is nothing to bill when it is quiet.
        expect(app(TruckRentService::class)->chargeMonth(now()))->toBe(0);
    });

    it('puts the owner share on the truck sheet as a cost', function (): void {
        $trip = ($this->haul)($this->shared, 1_000_000);

        $sheet = LedgerEntry::query()->where('trip_id', $trip->getKey())->firstOrFail();

        // Without this the truck shows its full income against almost no
        // costs and reads as the best performer on the fleet, when the fleet
        // in fact keeps fifteen per cent of it.
        expect($sheet->trip_income_cents)->toBe(1_000_000)
            ->and($sheet->owner_share_cents)->toBe(850_000)
            ->and($sheet->totalExpensesCents())->toBeGreaterThanOrEqual(850_000)
            ->and($sheet->netIncomeCents())->toBe(150_000);
    });

    it('still invoices the customer in full', function (): void {
        // What the fleet splits with the owner is none of the customer's
        // business: they hired Cargo Rush and are billed by Cargo Rush.
        $trip = ($this->haul)($this->shared, 1_000_000);

        expect($trip->invoice)->not->toBeNull();
    });

    it('owes nobody when the terms name nobody', function (): void {
        // A half-configured truck: marked as a share arrangement with no
        // partner to pay. It must not silently credit nothing to nobody, and
        // the fleet screen shows the gap.
        $this->shared->update(['owner_trucker_id' => null]);

        $trip = ($this->haul)($this->shared->refresh(), 1_000_000);

        expect($this->shared->refresh()->sharesRevenue())->toBeFalse()
            ->and(WalletEntry::query()->where('trip_id', $trip->getKey())->exists())->toBeFalse();
    });

    it('lets the owner be paid run by run, like any partner', function (): void {
        ($this->haul)($this->shared, 1_000_000);
        ($this->haul)($this->shared, 500_000);

        // The whole reason the share goes to a wallet rather than a ledger of
        // its own: everything already built for partners works for an owner.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->owner->id}/wallet", [
                'kind' => WalletEntryKind::Payout->value,
                'reference' => 'BDO 7781',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount_cents', -1_275_000);

        expect(WalletEntry::query()->fromWork()->whereNull('settled_by')->count())->toBe(0);
    });
});

describe('a sub-contracted truck', function (): void {
    it('keeps 12% and credits the operator the rest', function (): void {
        $operator = ($this->owner)('Boyet Aquino');

        $truck = ($this->truck)([
            'arrangement' => VehicleArrangement::SubContracted->value,
            'owner_name' => 'Boyet Aquino',
            'owner_trucker_id' => $operator->getKey(),
        ]);

        // No rate set, so the arrangement's own applies — 12%, the same as a
        // partner trucker is on.
        expect($truck->shareRateBp())->toBe(1200);

        $trip = ($this->haul)($truck, 1_000_000);

        $entry = WalletEntry::query()->where('trip_id', $trip->getKey())->firstOrFail();

        expect($entry->amount_cents)->toBe(880_000)
            ->and($entry->rate_bp)->toBe(1200);
    });

    it('nets against commission the operator owes on their own work', function (): void {
        // The point of one account. A sub-contractor hauling the fleet's work
        // and their own ends the week with a single figure either way.
        $operator = ($this->owner)('Boyet Aquino');

        $truck = ($this->truck)([
            'arrangement' => VehicleArrangement::SubContracted->value,
            'owner_trucker_id' => $operator->getKey(),
        ]);

        ($this->haul)($truck, 1_000_000);

        // …and a run of their own, which charges them 12% instead.
        WalletEntry::create([
            'trucker_id' => $operator->getKey(),
            'kind' => WalletEntryKind::Commission->value,
            'amount_cents' => -120_000,
            'occurred_on' => now()->toDateString(),
        ]);

        expect(WalletEntry::balanceFor($operator->getKey()))->toBe(760_000);
    });
});

describe('one counterparty per run', function (): void {
    /**
     * A trip cannot owe two people, and used to try.
     *
     * A row carrying both a `trucker_id` and a revenue-share `vehicle_id` made
     * the partner path and the owner path both fire. The second insert hit the
     * unique index on (`trip_id`, `kind`) — which was right to refuse it — and
     * the whole delivery rolled back with a 500, so the driver could not close
     * the run at all. Two bugs in one: money asked for twice, and a hand-off
     * that could not complete.
     */
    it('pays once, and closes the run, when a trip names both', function (): void {
        $partner = ($this->owner)('Partner');
        $owner = ($this->owner)('Truck owner');

        $truck = ($this->truck)([
            'arrangement' => VehicleArrangement::RentedShare->value,
            'share_bp' => 1500,
            'owner_trucker_id' => $owner->getKey(),
        ]);

        $trip = Trip::create([
            'customer_id' => $this->customer->getKey(),
            'origin' => 'Iponan',
            'destination' => 'Bukidnon',
            'cargo' => 'Rice',
            'weight_kg' => 10_000,
            'status' => StatusValue::Assigned->value,
            'scheduled_at' => now()->addDay(),
            'price_cents' => 1_000_000,
            'currency' => 'PHP',
            'vehicle_id' => $truck->getKey(),
            'driver_id' => $this->driver->getKey(),
            // The contradiction. Nothing in the schema forbids it.
            'trucker_id' => $partner->getKey(),
        ]);

        $this->passPreTripCheck($trip->getKey());

        // It completes. That is the first half of the fix — a driver at a
        // warehouse door must never be unable to hand a load over because two
        // services disagreed about who to pay.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/complete", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();

        // And exactly one person is paid: the partner, because they are the
        // one who demonstrably hauled it.
        $entries = WalletEntry::query()->where('trip_id', $trip->getKey())->get();

        expect($entries)->toHaveCount(1)
            ->and($entries->first()->trucker_id)->toBe($partner->getKey());

        expect(WalletEntry::query()->where('trucker_id', $owner->getKey())->exists())->toBeFalse();
    });

    it('does not put an owner share on the sheet when a partner was paid', function (): void {
        // The cost has to follow the payment. Crediting the partner and then
        // charging the truck's sheet for an owner share nobody received would
        // understate that unit by the same figure twice over.
        $partner = ($this->owner)('Partner');
        $owner = ($this->owner)('Truck owner');

        $truck = ($this->truck)([
            'arrangement' => VehicleArrangement::RentedShare->value,
            'share_bp' => 1500,
            'owner_trucker_id' => $owner->getKey(),
        ]);

        $trip = Trip::create([
            'customer_id' => $this->customer->getKey(),
            'origin' => 'Iponan', 'destination' => 'Bukidnon', 'cargo' => 'Rice',
            'weight_kg' => 10_000, 'status' => StatusValue::Assigned->value,
            'scheduled_at' => now()->addDay(), 'price_cents' => 1_000_000, 'currency' => 'PHP',
            'vehicle_id' => $truck->getKey(),
            'driver_id' => $this->driver->getKey(),
            'trucker_id' => $partner->getKey(),
        ]);

        $this->passPreTripCheck($trip->getKey());

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/complete", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();

        expect(LedgerEntry::query()->where('trip_id', $trip->getKey())->firstOrFail()->owner_share_cents)
            ->toBe(0);
    });
});

describe('what a settled run records about itself', function (): void {
    it('freezes the rate on a hired-truck run, as it does on a partner run', function (): void {
        // This was missing. A hired-truck run left both columns null, so the
        // office could not say what had been taken without finding the wallet
        // row — and the audit story the whole feature rests on held for
        // partner work and quietly not for hired trucks.
        $owner = ($this->owner)('Delfin Uy');

        $truck = ($this->truck)([
            'arrangement' => VehicleArrangement::RentedShare->value,
            'share_bp' => 1500,
            'owner_trucker_id' => $owner->getKey(),
        ]);

        $trip = ($this->haul)($truck, 1_000_000);

        expect($trip->commission_bp)->toBe(1500)
            ->and($trip->commission_cents)->toBe(150_000);
    });

    it('keeps the frozen rate when the terms are renegotiated afterwards', function (): void {
        $owner = ($this->owner)('Delfin Uy');

        $truck = ($this->truck)([
            'arrangement' => VehicleArrangement::RentedShare->value,
            'share_bp' => 1500,
            'owner_trucker_id' => $owner->getKey(),
        ]);

        $trip = ($this->haul)($truck, 1_000_000);

        $truck->update(['share_bp' => 2000]);

        // What was settled at 15% stays settled at 15%.
        expect($trip->refresh()->commission_bp)->toBe(1500);
    });
});

describe('the fleet screen', function (): void {
    it('names the terms and whoever is paid for each truck', function (): void {
        $owner = ($this->owner)('Delfin Uy');

        ($this->truck)([
            'arrangement' => VehicleArrangement::RentedShare->value,
            'wheels' => 10,
            'owner_trucker_id' => $owner->getKey(),
            'share_bp' => 1500,
        ]);

        $row = collect($this->actingAs($this->admin)->getJson('/api/v1/vehicles')->assertOk()->json('data'))
            ->firstWhere('arrangement', VehicleArrangement::RentedShare->value);

        expect($row['hired'])->toBeTrue()
            ->and($row['share_bp'])->toBe(1500)
            ->and($row['shares_revenue'])->toBeTrue()
            ->and($row['wheels'])->toBe(10)
            ->and($row['owner_trucker_name'])->toBe('Delfin Uy');
    });

    it('refuses a share arrangement with nobody to pay', function (): void {
        // Caught at the form rather than a month later, when the owner asks
        // where their money went.
        $this->actingAs($this->admin)
            ->postJson('/api/v1/vehicles', [
                'plate' => 'NEW-0001',
                'model' => 'Hino 500',
                'registration_no' => 'REG-0001',
                'capacity_kg' => 15_000,
                'arrangement' => VehicleArrangement::RentedShare->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('owner_trucker_id');
    });

    it('refuses a rented truck with no rent', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/vehicles', [
                'plate' => 'NEW-0002',
                'model' => 'Hino 500',
                'registration_no' => 'REG-0002',
                'capacity_kg' => 15_000,
                'arrangement' => VehicleArrangement::Rented->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rent_cents');
    });
});
