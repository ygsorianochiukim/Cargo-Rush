<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Services\TruckRentService;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * Everything the fleet owes, in one list.
 *
 * The money going out had grown into four places that never met — a partner's
 * wallet, a hired truck's rent, a supplier's bill, and whatever else was filed
 * as spend. Each screen was right about its own corner and nobody could answer
 * "what do we owe this week" without opening all four and adding up.
 *
 * What these tests mostly hold is the **total**, because that is the figure the
 * page exists for and the one way of being wrong that matters: counting
 * something twice, or leaving something out.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->payables = fn () => $this->actingAs($this->admin)
        ->getJson('/api/v1/finance/payables');

    /** A partner the fleet owes, by crediting their wallet directly. */
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

        // A rented truck, billed for the month.
        $rented = Vehicle::create([
            'plate' => 'RENT-1', 'model' => 'Hino', 'registration_no' => 'R1',
            'capacity_kg' => 15_000, 'status' => StatusValue::Available->value,
            'arrangement' => VehicleArrangement::Rented->value,
            'owner_name' => 'Delfin Hauling', 'rent_cents' => 5_000_000,
        ]);
        app(TruckRentService::class)->chargeMonth(now());

        // And an ordinary unpaid expense. The category is made here rather
        // than seeded: these rows are the office's once they exist, so the
        // suite does not lay down a catalogue it then has to keep in step.
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

        // ₱8,800 + ₱50,000 + ₱2,500.
        expect($body['total_cents'])->toBe(880_000 + 5_000_000 + 250_000);
    });

    it('does not count a rented truck rent twice', function (): void {
        // Rent is an expense, and it has a group of its own. Counted in both
        // the total would be wrong in the one figure this page exists to get
        // right.
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

        // A negative balance is the other direction. Showing it here as a
        // negative payable would quietly reduce what the week costs.
        $body = ($this->payables)()->assertOk()->json('data');

        expect($body['total_cents'])->toBe(0)
            ->and(($this->group)($body, 'truckers')['count'])->toBe(0);
    });

    it('still owes a partner whose payment has not landed', function (): void {
        $trucker = ($this->owed)('Boyet', 880_000);

        $this->actingAs($this->admin)->postJson("/api/v1/truckers/{$trucker->id}/wallet", [
            'kind' => WalletEntryKind::Payout->value,
        ])->assertCreated();

        // A transfer in flight has paid nobody. The line stays, and says how
        // much of it is already out of the door.
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
        $owner = ($this->owed)('Delfin Uy', 850_000);

        Vehicle::create([
            'plate' => 'TEN-1', 'model' => 'Hino', 'registration_no' => 'R9',
            'capacity_kg' => 15_000, 'status' => StatusValue::Available->value,
            'arrangement' => VehicleArrangement::RentedShare->value,
            'wheels' => 10, 'share_bp' => 1500, 'owner_trucker_id' => $owner->getKey(),
        ]);

        // "Trucker" covers three arrangements, and somebody writing a cheque
        // is choosing between them.
        expect(($this->group)(($this->payables)()->assertOk()->json('data'), 'truckers')['lines'][0]['detail'])
            ->toBe('Rented 10-wheeler owner · TEN-1');
    });

    it('calls an ordinary partner a partner', function (): void {
        ($this->owed)('Boyet', 880_000);

        expect(($this->group)(($this->payables)()->assertOk()->json('data'), 'truckers')['lines'][0]['detail'])
            ->toBe('Partner trucker');
    });

    it('points every line at the screen that settles it', function (): void {
        ($this->owed)('Boyet', 880_000);

        $line = ($this->group)(($this->payables)()->assertOk()->json('data'), 'truckers')['lines'][0];

        // The page is a roll-up: it tells you where to go rather than
        // growing three settle forms of its own.
        expect($line['settle_at'])->toBe('/truckers');
    });
});

describe('who may read it', function (): void {
    it('is closed to somebody without the finance permission', function (): void {
        $dispatcher = User::factory()->create(['role' => 'dispatcher']);

        $this->actingAs($dispatcher)->getJson('/api/v1/finance/payables')->assertForbidden();
    });

    it('shows a figure without granting the right to pay it', function (): void {
        // The point of putting this on `finance.view`: a manager can see what
        // the week costs without being able to move any of it.
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
