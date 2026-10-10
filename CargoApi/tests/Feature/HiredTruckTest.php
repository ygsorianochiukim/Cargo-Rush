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

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
    $this->driver = Driver::query()->firstOrFail();

    $this->owner = fn (string $name) => Trucker::factory()->approved()->create([
        'name' => $name,
        'licence_no' => null,
    ]);

    $this->truck = fn (array $terms = []) => Vehicle::create([
        'plate' => 'HIRE-'.fake()->unique()->numberBetween(1000, 9999),
        'model' => 'Isuzu Forward',
        'registration_no' => fake()->unique()->bothify('REG-####'),
        'capacity_kg' => 15_000,
        'status' => StatusValue::Available->value,
        ...$terms,
    ]);

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
            ->and($rent->status)->toBe(StatusValue::Pending)
            ->and($rent->truck_id)->not->toBeNull();
    });

    it('does not bill the same month twice', function (): void {
        expect(app(TruckRentService::class)->chargeMonth(now()))->toBe(1);
        expect(app(TruckRentService::class)->chargeMonth(now()))->toBe(0);

        expect(Expense::query()->where('vehicle_id', $this->rented->getKey())->count())->toBe(1);
    });

    it('bills a truck with no rent recorded nothing at all', function (): void {
        $this->rented->update(['rent_cents' => null]);

        expect(app(TruckRentService::class)->chargeMonth(now()))->toBe(0);
    });

    it('charges rent whether the truck worked or not', function (): void {
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

        expect($entry->kind)->toBe(WalletEntryKind::Earning)
            ->and($entry->amount_cents)->toBe(850_000)
            ->and($entry->rate_bp)->toBe(1500)
            ->and($entry->gross_cents)->toBe(1_000_000)
            ->and($entry->trucker_id)->toBe($this->owner->getKey());
    });

    it('charges no monthly rent', function (): void {
        expect(app(TruckRentService::class)->chargeMonth(now()))->toBe(0);
    });

    it('puts the owner share on the truck sheet as a cost', function (): void {
        $trip = ($this->haul)($this->shared, 1_000_000);

        $sheet = LedgerEntry::query()->where('trip_id', $trip->getKey())->firstOrFail();

        expect($sheet->trip_income_cents)->toBe(1_000_000)
            ->and($sheet->owner_share_cents)->toBe(850_000)
            ->and($sheet->totalExpensesCents())->toBeGreaterThanOrEqual(850_000)
            ->and($sheet->netIncomeCents())->toBe(150_000);
    });

    it('still invoices the customer in full', function (): void {
        $trip = ($this->haul)($this->shared, 1_000_000);

        expect($trip->invoice)->not->toBeNull();
    });

    it('owes nobody when the terms name nobody', function (): void {
        $this->shared->update(['owner_trucker_id' => null]);

        $trip = ($this->haul)($this->shared->refresh(), 1_000_000);

        expect($this->shared->refresh()->sharesRevenue())->toBeFalse()
            ->and(WalletEntry::query()->where('trip_id', $trip->getKey())->exists())->toBeFalse();
    });

    it('lets the owner be paid run by run, like any partner', function (): void {
        ($this->haul)($this->shared, 1_000_000);
        ($this->haul)($this->shared, 500_000);

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

        expect($truck->shareRateBp())->toBe(1200);

        $trip = ($this->haul)($truck, 1_000_000);

        $entry = WalletEntry::query()->where('trip_id', $trip->getKey())->firstOrFail();

        expect($entry->amount_cents)->toBe(880_000)
            ->and($entry->rate_bp)->toBe(1200);
    });

    it('nets against commission the operator owes on their own work', function (): void {
        $operator = ($this->owner)('Boyet Aquino');

        $truck = ($this->truck)([
            'arrangement' => VehicleArrangement::SubContracted->value,
            'owner_trucker_id' => $operator->getKey(),
        ]);

        ($this->haul)($truck, 1_000_000);

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
            'trucker_id' => $partner->getKey(),
        ]);

        $this->passPreTripCheck($trip->getKey());

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/complete", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();

        $entries = WalletEntry::query()->where('trip_id', $trip->getKey())->get();

        expect($entries)->toHaveCount(1)
            ->and($entries->first()->trucker_id)->toBe($partner->getKey());

        expect(WalletEntry::query()->where('trucker_id', $owner->getKey())->exists())->toBeFalse();
    });

    it('does not put an owner share on the sheet when a partner was paid', function (): void {
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

        expect(LedgerEntry::query()->where('trip_id', $trip->getKey())->doesntExist())->toBeTrue();
    });
});

describe('what a settled run records about itself', function (): void {
    it('freezes the rate on a hired-truck run, as it does on a partner run', function (): void {
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
