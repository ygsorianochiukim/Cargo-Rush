<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerVehicle;
use App\Domain\Trucker\Models\WalletEntry;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * The fleet's income from partners' runs: its commission, and nothing else.
 *
 * On a ₱5,000 run a partner hauls, the customer pays ₱5,000 plus ₱600 VAT;
 * the VAT is the government's, the ₱4,400 is the partner's, and the ₱600 left
 * is the fleet's income. These pin that end to end, through delivery and into
 * the Quarterly Summary and Sales, from both ways a partner can get a run.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
    $this->user = User::factory()->create(['role' => Role::Trucker->value, 'company_id' => $this->company->getKey()]);
    $this->trucker = Trucker::factory()->approved()->create(['user_id' => $this->user->getKey()]);
    TruckerVehicle::factory()->create(['trucker_id' => $this->trucker->id, 'capacity_kg' => 20_000, 'status' => 'available']);

    $this->travelTo(now()->setDate(2026, 8, 12)->setTime(10, 0));

    /** A ₱5,000 run, taken to delivery either way a partner can get one. */
    $this->deliver = function (bool $direct): Trip {
        $trip = Trip::create([
            'customer_id' => $this->customer->getKey(), 'origin' => 'Malanang', 'destination' => 'Mambuaya',
            'cargo' => 'Goods', 'weight_kg' => 1_800, 'status' => StatusValue::Pending->value,
            'scheduled_at' => now()->addHour(), 'price_cents' => 500_000, 'currency' => 'PHP',
            ...($direct ? ['trucker_id' => $this->trucker->id] : []),
        ]);

        if ($direct) {
            $this->actingAs($this->user)->postJson("/api/v1/partner/jobs/{$trip->id}/accept")->assertCreated();
        } else {
            $this->actingAs($this->admin)
                ->postJson("/api/v1/trips/{$trip->id}/assign-trucker", ['trucker_id' => $this->trucker->id])
                ->assertOk();
        }

        $this->actingAs($this->user)->postJson("/api/v1/partner/trips/{$trip->id}/start")->assertOk();
        $this->actingAs($this->user)
            ->postJson("/api/v1/partner/trips/{$trip->id}/deliver", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();

        return $trip->refresh();
    };

    $this->quarter = fn () => $this->actingAs($this->admin)
        ->getJson('/api/v1/finance/summary?year=2026&quarter=q3')
        ->assertOk()
        ->json('data.totals');
});

it('counts the fleet\'s ₱600 as income on a ₱5,000 run it billed, and the VAT beside it', function (): void {
    ($this->deliver)(false);

    $totals = ($this->quarter)();

    expect($totals['trucker_commission_cents'])->toBe(60_000)
        ->and($totals['total_income_cents'])->toBe(60_000)
        ->and($totals['vat_collected_cents'])->toBe(60_000)
        // The partner's ₱4,400 is neither income nor a cost.
        ->and($totals['total_expenses_cents'])->toBe(0)
        ->and($totals['net_income_cents'])->toBe(60_000)
        // Owed to them and held for them — so not taken off the fleet again.
        ->and($totals['held_for_partners_cents'])->toBe(440_000)
        ->and($totals['actual_income_cents'])->toBe(60_000);
});

it('still reads ₱600 once the partner has been paid their ₱4,400', function (): void {
    ($this->deliver)(false);

    WalletEntry::create([
        'trucker_id' => $this->trucker->id,
        'kind' => WalletEntryKind::Payout->value,
        'status' => StatusValue::Paid->value,
        'amount_cents' => -440_000,
        'occurred_on' => now()->toDateString(),
    ]);

    $totals = ($this->quarter)();

    expect($totals['trucker_payouts_cents'])->toBe(440_000)
        ->and($totals['total_expenses_cents'])->toBe(0)
        ->and($totals['net_income_cents'])->toBe(60_000)
        ->and($totals['actual_income_cents'])->toBe(60_000);
});

it('counts the ₱600 commission on a run the customer gave the partner directly, with no VAT of ours', function (): void {
    ($this->deliver)(true);

    $totals = ($this->quarter)();

    expect($totals['trucker_commission_cents'])->toBe(60_000)
        ->and($totals['net_income_cents'])->toBe(60_000)
        // The partner billed the customer; the fleet raised no invoice.
        ->and($totals['vat_collected_cents'])->toBe(0);
});

it('puts the commission on Sales, on the day the run was delivered', function (): void {
    ($this->deliver)(false);

    $sales = $this->actingAs($this->admin)
        ->getJson('/api/v1/finance/sales?granularity=daily&from=2026-08-01&to=2026-08-31')
        ->assertOk()
        ->json('data');

    expect($sales['totals']['sales_cents'])->toBe(60_000)
        ->and($sales['totals']['expenses_cents'])->toBe(0);
});

it('shows it as income on the dashboard', function (): void {
    ($this->deliver)(false);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/dashboard/receivables')
        ->assertOk()
        ->assertJsonPath('data.income_cents', 60_000)
        ->assertJsonPath('data.expenses_cents', 0)
        ->assertJsonPath('data.net_income_cents', 60_000);
});
