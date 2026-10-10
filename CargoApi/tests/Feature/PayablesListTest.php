<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
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

    $this->payables = fn () => $this->actingAs($this->admin)
        ->getJson('/api/v1/finance/payables');

    $this->owed = function (string $name, int $cents): Trucker {
        $trucker = Trucker::factory()->approved()->create([
            'name' => $name,
            'licence_no' => null,
        ]);

        WalletEntry::create([
            'trucker_id' => $trucker->getKey(),
            'kind' => WalletEntryKind::Earning->value,
            'amount_cents' => $cents,
            'occurred_on' => now()->toDateString(),
        ]);

        return $trucker;
    };

    $this->group = fn (array $body, string $key): array => collect($body['groups'])
        ->firstWhere('key', $key);
});

describe('what it gathers', function (): void {
    it('adds up across all four places money goes out', function (): void {
        ($this->owed)('Boyet', 880_000);

        $rented = Vehicle::create([
            'plate' => 'RENT-1', 'model' => 'Hino', 'registration_no' => 'R1',
            'capacity_kg' => 15_000, 'status' => StatusValue::Available->value,
            'arrangement' => VehicleArrangement::Rented->value,
            'owner_name' => 'Delfin Hauling', 'rent_cents' => 5_000_000,
        ]);
        app(TruckRentService::class)->chargeMonth(now());

        $repairs = ExpenseCategory::create([
            'key' => 'repairs',
            'name' => 'Repairs',
            'icon' => 'gauge',
            'position' => 40,
        ]);

        Expense::create([
            'category_id' => $repairs->getKey(),
            'date' => now()->toDateString(),
            'amount_cents' => 250_000,
            'currency' => 'PHP',
            'payee' => 'Roadside Motors',
            'status' => StatusValue::Pending->value,
        ]);

        $body = ($this->payables)()->assertOk()->json('data');

        expect($body['total_cents'])->toBe(880_000 + 5_000_000 + 250_000);
    });

    it('does not count a rented truck rent twice', function (): void {
        Vehicle::create([
            'plate' => 'RENT-2', 'model' => 'Hino', 'registration_no' => 'R2',
            'capacity_kg' => 15_000, 'status' => StatusValue::Available->value,
            'arrangement' => VehicleArrangement::Rented->value,
            'rent_cents' => 5_000_000,
        ]);
        app(TruckRentService::class)->chargeMonth(now());

        $body = ($this->payables)()->assertOk()->json('data');

        expect($body['total_cents'])->toBe(5_000_000)
            ->and(($this->group)($body, 'rented_trucks')['count'])->toBe(1)
            ->and(($this->group)($body, 'other')['count'])->toBe(0);
    });

    it('leaves out a partner who owes the fleet rather than the other way', function (): void {
        $debtor = Trucker::factory()->approved()->create(['licence_no' => null]);

        WalletEntry::create([
            'trucker_id' => $debtor->getKey(),
            'kind' => WalletEntryKind::Commission->value,
            'amount_cents' => -120_000,
            'occurred_on' => now()->toDateString(),
        ]);

        $body = ($this->payables)()->assertOk()->json('data');

        expect($body['total_cents'])->toBe(0)
            ->and(($this->group)($body, 'truckers')['count'])->toBe(0);
    });

    it('still owes a partner whose payment has not landed', function (): void {
        $trucker = ($this->owed)('Boyet', 880_000);

        $this->actingAs($this->admin)->postJson("/api/v1/truckers/{$trucker->id}/wallet", [
            'kind' => WalletEntryKind::Payout->value,
        ])->assertCreated();

        $body = ($this->payables)()->assertOk()->json('data');

        expect($body['total_cents'])->toBe(880_000)
            ->and($body['in_flight_cents'])->toBe(880_000)
            ->and(($this->group)($body, 'truckers')['lines'][0]['in_flight_cents'])->toBe(880_000);
    });

    it('drops a partner off the list once the payment lands', function (): void {
        $trucker = ($this->owed)('Boyet', 880_000);

        $this->actingAs($this->admin)->postJson("/api/v1/truckers/{$trucker->id}/wallet", [
            'kind' => WalletEntryKind::Payout->value,
            'method' => 'cash',
            'cleared' => true,
        ])->assertCreated();

        expect(($this->payables)()->assertOk()->json('data.total_cents'))->toBe(0);
    });
});

describe('what each line says', function (): void {
    it('names the arrangement a partner is owed under', function (): void {
        $operator = ($this->owed)('Jun Abad', 880_000);

        Vehicle::create([
            'plate' => 'SUB-1', 'model' => 'Hino', 'registration_no' => 'R9',
            'capacity_kg' => 15_000, 'status' => StatusValue::Available->value,
            'arrangement' => VehicleArrangement::SubContracted->value,
            'share_bp' => 1200, 'owner_trucker_id' => $operator->getKey(),
        ]);

        expect(($this->group)(($this->payables)()->assertOk()->json('data'), 'truckers')['lines'][0]['detail'])
            ->toBe('Sub-contractor · SUB-1');
    });

    it('calls an ordinary partner a partner', function (): void {
        ($this->owed)('Boyet', 880_000);

        expect(($this->group)(($this->payables)()->assertOk()->json('data'), 'truckers')['lines'][0]['detail'])
            ->toBe('Partner trucker');
    });

    it('points every line at the screen that settles it', function (): void {
        ($this->owed)('Boyet', 880_000);

        $line = ($this->group)(($this->payables)()->assertOk()->json('data'), 'truckers')['lines'][0];

        expect($line['settle_at'])->toBe('/truckers');
    });
});

describe('rented trucks', function (): void {
    beforeEach(function (): void {
        $this->customer = Customer::query()->firstOrFail();
        $this->driver = Driver::query()->firstOrFail();

        $this->shareOwner = Trucker::factory()->approved()->create([
            'name' => 'Delfin Uy',
            'licence_no' => null,
        ]);

        $this->shareTruck = Vehicle::create([
            'plate' => 'TEN-1', 'model' => 'Hino', 'registration_no' => 'R9',
            'capacity_kg' => 15_000, 'status' => StatusValue::Available->value,
            'arrangement' => VehicleArrangement::RentedShare->value,
            'wheels' => 10, 'share_bp' => 1500,
            'owner_name' => 'Delfin Uy', 'owner_trucker_id' => $this->shareOwner->getKey(),
        ]);

        $this->haul = function (Vehicle $vehicle, int $priceCents): void {
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
        };
    });

    it('owes a share truck nothing until it does a run', function (): void {
        $body = ($this->payables)()->assertOk()->json('data');

        expect($body['total_cents'])->toBe(0)
            ->and(($this->group)($body, 'rented_trucks')['count'])->toBe(0);
    });

    it('owes a share truck its cut of each delivered run', function (): void {
        ($this->haul)($this->shareTruck, 1_000_000);
        ($this->haul)($this->shareTruck, 600_000);

        $body = ($this->payables)()->assertOk()->json('data');
        $rented = ($this->group)($body, 'rented_trucks');

        expect($rented['count'])->toBe(1)
            ->and($rented['total_cents'])->toBe(1_360_000)
            ->and($rented['lines'][0]['name'])->toBe('TEN-1')
            ->and($rented['lines'][0]['detail'])->toBe('Delfin Uy · revenue share, fleet keeps 15% · 2 unpaid runs')
            ->and($rented['lines'][0]['settle_at'])->toBe('/truckers')
            ->and($rented['lines'][0]['id'])->toBe($this->shareOwner->getKey());

        expect(($this->group)($body, 'truckers')['count'])->toBe(0)
            ->and($body['total_cents'])->toBe(1_360_000);
    });

    it('lists flat rent and shares side by side', function (): void {
        Vehicle::create([
            'plate' => 'RENT-1', 'model' => 'Hino', 'registration_no' => 'R1',
            'capacity_kg' => 15_000, 'status' => StatusValue::Available->value,
            'arrangement' => VehicleArrangement::Rented->value,
            'owner_name' => 'Delfin Hauling', 'rent_cents' => 5_000_000,
        ]);
        app(TruckRentService::class)->chargeMonth(now());

        ($this->haul)($this->shareTruck, 1_000_000);

        $rented = ($this->group)(($this->payables)()->assertOk()->json('data'), 'rented_trucks');

        expect($rented['count'])->toBe(2)
            ->and($rented['total_cents'])->toBe(5_000_000 + 850_000)
            ->and(collect($rented['lines'])->pluck('settle_at')->all())->toBe(['/expenses', '/truckers']);
    });

    it('drops the share line once the owner is paid', function (): void {
        ($this->haul)($this->shareTruck, 1_000_000);

        $this->actingAs($this->admin)->postJson("/api/v1/truckers/{$this->shareOwner->id}/wallet", [
            'kind' => WalletEntryKind::Payout->value,
            'method' => 'cash',
            'cleared' => true,
        ])->assertCreated();

        expect(($this->payables)()->assertOk()->json('data.total_cents'))->toBe(0);
    });
});

describe('who may read it', function (): void {
    it('is closed to somebody without the finance permission', function (): void {
        $dispatcher = User::factory()->create(['role' => 'dispatcher']);

        $this->actingAs($dispatcher)->getJson('/api/v1/finance/payables')->assertForbidden();
    });

    it('shows a figure without granting the right to pay it', function (): void {
        $accountant = User::factory()->create(['role' => 'accountant']);

        $this->actingAs($accountant)->getJson('/api/v1/finance/payables')->assertOk();

        $trucker = ($this->owed)('Boyet', 880_000);

        $this->actingAs($accountant)
            ->postJson("/api/v1/truckers/{$trucker->id}/wallet", [
                'kind' => WalletEntryKind::Payout->value,
            ])
            ->assertForbidden();
    });
});
