<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
    $this->driver = Driver::query()->firstOrFail();

    $this->calendar = PayrollCalendar::default();
    $this->closed = $this->calendar->justClosed();
    $this->previous = $this->calendar->justClosed($this->closed->start);

    $this->owner = Trucker::factory()->approved()->create(['name' => 'Jun Abad', 'licence_no' => null]);

    $this->truck = Vehicle::create([
        'plate' => 'SUB-1', 'model' => 'Hino', 'registration_no' => 'R9',
        'capacity_kg' => 15_000, 'status' => StatusValue::Available->value,
        'arrangement' => VehicleArrangement::SubContracted->value,
        'share_bp' => 1200, 'owner_trucker_id' => $this->owner->getKey(),
    ]);

    $this->haul = function (int $priceCents, Carbon $on, ?Vehicle $vehicle = null, int $driverAllowance = 0, int $helperAllowance = 0): Trip {
        $this->travelTo($on->copy()->setTime(14, 0));

        $trip = Trip::create([
            'customer_id' => $this->customer->getKey(),
            'origin' => 'Iponan',
            'destination' => 'Bukidnon',
            'cargo' => 'Rice',
            'weight_kg' => 10_000,
            'status' => StatusValue::Assigned->value,
            'scheduled_at' => now()->addHour(),
            'price_cents' => $priceCents,
            'currency' => 'PHP',
            'vehicle_id' => ($vehicle ?? $this->truck)->getKey(),
            'driver_id' => $this->driver->getKey(),
            'driver_allowance_cents' => $driverAllowance,
            'helper_allowance_cents' => $helperAllowance,
        ]);

        $this->passPreTripCheck($trip->getKey());

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/complete", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();

        $this->travelBack();

        return $trip->refresh();
    };

    $this->fuelOnRun = function (Trip $trip, int $cents): void {
        $log = DeliveryLog::where('trip_id', $trip->getKey())->firstOrFail();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/delivery-logs/{$log->id}/fuel", ['fuel_litres' => 40, 'fuel_cost_cents' => $cents])
            ->assertOk();
    };

    $this->fill = function (int $cents, Carbon $on, ?Vehicle $vehicle = null): void {
        $this->actingAs($this->admin)->postJson('/api/v1/fuel', [
            'vehicle_id' => ($vehicle ?? $this->truck)->getKey(),
            'litres' => 20,
            'amount_cents' => $cents,
            'odometer_km' => 120_000,
            'receipt_no' => 'OR-'.$on->format('md'),
            'logged_at' => $on->copy()->setTime(9, 0)->toIso8601String(),
            'status' => StatusValue::Active->value,
        ])->assertCreated();
    };

    $this->service = function (int $cents, Carbon $on, ?Vehicle $vehicle = null): void {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/vehicles/'.($vehicle ?? $this->truck)->getKey().'/maintenance', [
                'kind' => 'Brake pads',
                'due_at' => $on->toDateString(),
                'status' => StatusValue::Delivered->value,
                'cost_cents' => $cents,
                'completed_on' => $on->toDateString(),
                'reference' => 'JO-'.$on->format('md'),
            ])
            ->assertCreated();
    };

    $this->payables = fn () => $this->actingAs($this->admin)->getJson('/api/v1/finance/payables');

    $this->group = fn (array $body, string $key): array => collect($body['groups'])->firstWhere('key', $key);

    $this->settle = fn (array $body = []) => $this->actingAs($this->admin)
        ->postJson("/api/v1/finance/payables/owners/{$this->owner->id}/settle", [
            'start' => $this->closed->start->toDateString(),
            'end' => $this->closed->end->toDateString(),
            ...$body,
        ]);

    $this->statement = fn () => $this->actingAs($this->admin)
        ->getJson('/api/v1/finance/payables/owners?'.http_build_query([
            'from' => $this->previous->start->toDateString(),
            'to' => now()->toDateString(),
            'trucker' => $this->owner->getKey(),
        ]))
        ->assertOk()
        ->json('data');

    $this->history = fn () => $this->actingAs($this->admin)
        ->getJson("/api/v1/finance/payables/owners/{$this->owner->id}/history")
        ->assertOk()
        ->json('data');

    $this->cutoff = fn (array $statement, string $start): array => collect($statement['owners'][0]['cutoffs'])
        ->firstWhere('start', $start);
});

describe('what the owner is due', function (): void {
    it('owes the owner their share of the cutoff less fuel, allowances and maintenance', function (): void {
        $first = ($this->haul)(1_000_000, $this->closed->start->copy()->addDays(2), driverAllowance: 100_000);
        ($this->haul)(500_000, $this->closed->start->copy()->addDays(5));
        ($this->fuelOnRun)($first, 200_000);
        ($this->fill)(100_000, $this->closed->start->copy()->addDays(4));
        ($this->service)(50_000, $this->closed->start->copy()->addDays(6));

        $body = ($this->payables)()->assertOk()->json('data');
        $owners = ($this->group)($body, 'truck_owners');

        expect($owners['count'])->toBe(1)
            ->and($owners['lines'][0]['name'])->toBe('Jun Abad')
            ->and($owners['lines'][0]['amount_cents'])->toBe(880_000 + 440_000 - 300_000 - 100_000 - 50_000)
            ->and($owners['lines'][0]['in_flight_cents'])->toBe(0)
            ->and($owners['lines'][0]['detail'])->toBe(
                "SUB-1 · {$this->closed->label()} · 2 runs ₱13,200.00 − fuel ₱3,000.00 − allowance ₱1,000.00 − maintenance ₱500.00",
            )
            ->and($owners['lines'][0]['settle_at'])->toBe('/payables/owners')
            ->and($owners['lines'][0]['trucker_id'])->toBe($this->owner->getKey())
            ->and($owners['lines'][0]['period_start'])->toBe($this->closed->start->toDateString())
            ->and($owners['lines'][0]['period_end'])->toBe($this->closed->end->toDateString())
            ->and($owners['lines'][0]['due_on'])->toBe($this->calendar->releaseFor($this->closed->end)->toDateString());

        expect(($this->group)($body, 'truckers')['count'])->toBe(0)
            ->and($body['total_cents'])->toBe(870_000);
    });

    it('follows the rule: income less allowance and fuel, less the 12% company share', function (): void {
        $run = ($this->haul)(500_000, $this->closed->start->copy()->addDays(2), driverAllowance: 100_000);
        ($this->fuelOnRun)($run, 100_000);

        $cutoff = ($this->cutoff)(($this->statement)(), $this->closed->start->toDateString());

        expect($cutoff['gross_cents'])->toBe(500_000)
            ->and($cutoff['commission_cents'])->toBe(60_000)
            ->and($cutoff['share_cents'])->toBe(440_000)
            ->and($cutoff['allowance_cents'])->toBe(100_000)
            ->and($cutoff['fuel_cents'])->toBe(100_000)
            ->and($cutoff['maintenance_cents'])->toBe(0)
            ->and($cutoff['costs_cents'])->toBe(200_000)
            ->and($cutoff['net_cents'])->toBe(240_000);

        expect(($this->group)(($this->payables)()->assertOk()->json('data'), 'truck_owners')['lines'][0]['amount_cents'])
            ->toBe(240_000);
    });

    it('keeps one line per cutoff', function (): void {
        ($this->haul)(1_000_000, $this->previous->start->copy()->addDays(3));
        ($this->haul)(500_000, $this->closed->start->copy()->addDays(3));

        $lines = collect(($this->group)(($this->payables)()->assertOk()->json('data'), 'truck_owners')['lines']);

        expect($lines)->toHaveCount(2)
            ->and($lines->pluck('period_start')->all())->toBe([
                $this->previous->start->toDateString(),
                $this->closed->start->toDateString(),
            ])
            ->and($lines->pluck('amount_cents')->all())->toBe([880_000, 440_000]);
    });

    it('does not ask for the cutoff still running', function (): void {
        ($this->haul)(1_000_000, now());

        $body = ($this->payables)()->assertOk()->json('data');

        expect(($this->group)($body, 'truck_owners')['count'])->toBe(0)
            ->and($body['total_cents'])->toBe(0);

        $open = ($this->cutoff)(($this->statement)(), $this->closed->end->copy()->addDay()->toDateString());

        expect($open['state'])->toBe('open')
            ->and($open['share_cents'])->toBe(880_000);
    });

    it('shows the costs swallowing the share rather than hiding the cutoff', function (): void {
        $run = ($this->haul)(200_000, $this->closed->start->copy()->addDays(2));
        ($this->fuelOnRun)($run, 300_000);

        $line = ($this->group)(($this->payables)()->assertOk()->json('data'), 'truck_owners')['lines'][0];

        expect($line['amount_cents'])->toBe(0)
            ->and($line['detail'])->toContain('costs exceed the share by ₱1,240.00');
    });

    it('treats a rented share truck the same way', function (): void {
        $landlord = Trucker::factory()->approved()->create(['name' => 'Delfin Uy', 'licence_no' => null]);

        $rented = Vehicle::create([
            'plate' => 'TEN-1', 'model' => 'Hino', 'registration_no' => 'R1',
            'capacity_kg' => 15_000, 'status' => StatusValue::Available->value,
            'arrangement' => VehicleArrangement::RentedShare->value,
            'wheels' => 10, 'share_bp' => 1500, 'owner_trucker_id' => $landlord->getKey(),
        ]);

        $run = ($this->haul)(1_000_000, $this->closed->start->copy()->addDays(2), $rented, helperAllowance: 50_000);
        ($this->fuelOnRun)($run, 100_000);

        $body = ($this->payables)()->assertOk()->json('data');
        $line = ($this->group)($body, 'truck_owners')['lines'][0];

        expect(($this->group)($body, 'truck_owners')['count'])->toBe(1)
            ->and($line['name'])->toBe('Delfin Uy')
            ->and($line['amount_cents'])->toBe(850_000 - 100_000 - 50_000)
            ->and($line['detail'])->toBe("TEN-1 · {$this->closed->label()} · 1 run ₱8,500.00 − fuel ₱1,000.00 − allowance ₱500.00")
            ->and(($this->group)($body, 'rented_trucks')['count'])->toBe(0)
            ->and($body['total_cents'])->toBe(700_000);
    });
});

describe('the statement', function (): void {
    it('lists every run and every cost behind the figure', function (): void {
        $first = ($this->haul)(1_000_000, $this->closed->start->copy()->addDays(2));
        ($this->haul)(500_000, $this->closed->start->copy()->addDays(5));
        ($this->fuelOnRun)($first, 200_000);
        ($this->fill)(100_000, $this->closed->start->copy()->addDays(4));

        $statement = ($this->statement)();
        $owner = $statement['owners'][0];
        $cutoff = ($this->cutoff)($statement, $this->closed->start->toDateString());

        expect($owner['name'])->toBe('Jun Abad')
            ->and($owner['plates'])->toBe(['SUB-1'])
            ->and($owner['trucks'][0]['owner_keeps_bp'])->toBe(8800)
            ->and($owner['trucks'][0]['arrangement'])->toBe('subcontracted')
            ->and($cutoff['state'])->toBe('unpaid')
            ->and($cutoff['runs'])->toHaveCount(2)
            ->and($cutoff['runs'][0]['reference'])->toBe($first->reference)
            ->and($cutoff['runs'][0]['gross_cents'])->toBe(1_000_000)
            ->and($cutoff['runs'][0]['share_cents'])->toBe(880_000)
            ->and($cutoff['runs'][0]['fleet_keeps_bp'])->toBe(1200)
            ->and($cutoff['runs'][0]['plate'])->toBe('SUB-1')
            ->and(collect($cutoff['costs'])->pluck('cents')->all())->toBe([200_000, 100_000])
            ->and($cutoff['gross_cents'])->toBe(1_500_000)
            ->and($cutoff['commission_cents'])->toBe(180_000)
            ->and($cutoff['share_cents'])->toBe(1_320_000)
            ->and($cutoff['fuel_cents'])->toBe(300_000)
            ->and($cutoff['costs_cents'])->toBe(300_000)
            ->and($cutoff['net_cents'])->toBe(1_020_000)
            ->and($owner['totals']['unpaid_cents'])->toBe(1_020_000)
            ->and($owner['totals']['costs_cents'])->toBe(300_000);

        expect(($this->cutoff)($statement, $this->previous->start->toDateString())['state'])->toBe('nothing');
    });

    it('shows how each run\'s share and each peso of cost came about', function (): void {
        $run = ($this->haul)(1_000_000, $this->closed->start->copy()->addDays(2), driverAllowance: 100_000, helperAllowance: 40_000);
        ($this->fuelOnRun)($run, 200_000);
        ($this->fill)(100_000, $this->closed->start->copy()->addDays(4));
        ($this->service)(50_000, $this->closed->start->copy()->addDays(6));

        $cutoff = ($this->cutoff)(($this->statement)(), $this->closed->start->toDateString());
        $line = $cutoff['runs'][0];

        expect($line['customer'])->toBe($this->customer->name)
            ->and($line['price_cents'])->toBe(1_000_000)
            ->and($line['vat_in_price_cents'])->toBe(1_000_000 - $line['gross_cents'])
            ->and($line['vat_treatment_label'])->toBe($this->customer->vatTreatment()->label())
            ->and($line['commission_cents'])->toBe($line['gross_cents'] - $line['share_cents'])
            ->and($line['fleet_keeps_bp'])->toBe(1200);

        $costs = collect($cutoff['costs']);

        expect($costs->pluck('kind')->all())->toBe(['fuel', 'allowance', 'allowance', 'fuel', 'maintenance'])
            ->and($costs->pluck('source')->all())->toBe(['run', 'run', 'run', 'fill', 'job'])
            ->and($costs->pluck('cents')->all())->toBe([200_000, 100_000, 40_000, 100_000, 50_000])
            ->and($costs[0]['label'])->toBe("Run {$run->reference}")
            ->and($costs[0]['litres'])->toEqual(40)
            ->and($costs[1]['label'])->toBe("Driver allowance · run {$run->reference}")
            ->and($costs[2]['label'])->toBe("Helper allowance · run {$run->reference}")
            ->and($costs[3]['label'])->toBe('Fill · OR-'.$this->closed->start->copy()->addDays(4)->format('md'))
            ->and($costs[3]['plate'])->toBe('SUB-1')
            ->and($costs[4]['label'])->toBe('Brake pads')
            ->and($costs[4]['detail'])->toBe('JO-'.$this->closed->start->copy()->addDays(6)->format('md'))
            ->and($costs->sum('cents'))->toBe($cutoff['costs_cents'])
            ->and($cutoff['allowance_cents'])->toBe(140_000)
            ->and($cutoff['maintenance_cents'])->toBe(50_000);
    });
});

describe('settling a cutoff', function (): void {
    beforeEach(function (): void {
        $this->run = ($this->haul)(1_000_000, $this->closed->start->copy()->addDays(2), driverAllowance: 100_000);
        ($this->haul)(500_000, $this->closed->start->copy()->addDays(5));
        ($this->fuelOnRun)($this->run, 200_000);
        ($this->fill)(100_000, $this->closed->start->copy()->addDays(4));
    });

    it('pays the share less costs and takes the costs off the wallet', function (): void {
        $payout = ($this->settle)(['method' => 'cash', 'cleared' => true, 'reference' => 'CV-1001'])
            ->assertCreated()
            ->json('data');

        expect($payout['kind'])->toBe('payout')
            ->and($payout['amount_cents'])->toBe(-920_000)
            ->and($payout['status'])->toBe('paid')
            ->and($payout['reference'])->toBe('CV-1001')
            ->and($payout['period_start'])->toBe($this->closed->start->toDateString())
            ->and($payout['period_end'])->toBe($this->closed->end->toDateString());

        $costs = WalletEntry::query()
            ->where('trucker_id', $this->owner->getKey())
            ->where('kind', WalletEntryKind::Adjustment->value)
            ->firstOrFail();

        expect($costs->amount_cents)->toBe(-400_000)
            ->and($costs->note)->toBe("Fuel ₱3,000.00, allowances ₱1,000.00 on {$this->closed->label()}")
            ->and($costs->settled_by)->toBe($payout['id'])
            ->and(WalletEntry::balanceFor($this->owner->getKey()))->toBe(0);

        expect(WalletEntry::query()
            ->where('trucker_id', $this->owner->getKey())
            ->where('kind', WalletEntryKind::Earning->value)
            ->whereNull('settled_by')
            ->count())->toBe(0);

        $body = ($this->payables)()->assertOk()->json('data');

        expect(($this->group)($body, 'truck_owners')['count'])->toBe(0)
            ->and($body['total_cents'])->toBe(0);

        $cutoff = ($this->cutoff)(($this->statement)(), $this->closed->start->toDateString());

        expect($cutoff['state'])->toBe('paid')
            ->and($cutoff['settlement']['kind'])->toBe('payout')
            ->and($cutoff['settlement']['amount_cents'])->toBe(920_000)
            ->and($cutoff['runs'][0]['state'])->toBe('paid');
    });

    it('keeps the cutoff on the list while the transfer is on its way', function (): void {
        $payout = ($this->settle)()->assertCreated()->json('data');

        $body = ($this->payables)()->assertOk()->json('data');
        $line = ($this->group)($body, 'truck_owners')['lines'][0];

        expect($line['amount_cents'])->toBe(920_000)
            ->and($line['in_flight_cents'])->toBe(920_000)
            ->and($body['in_flight_cents'])->toBe(920_000);

        expect(($this->cutoff)(($this->statement)(), $this->closed->start->toDateString())['state'])->toBe('processing');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->owner->id}/wallet/{$payout['id']}/confirm")
            ->assertOk();

        expect(($this->group)(($this->payables)()->assertOk()->json('data'), 'truck_owners')['count'])->toBe(0);
    });

    it('will not settle the same cutoff twice', function (): void {
        ($this->settle)()->assertCreated();

        ($this->settle)()
            ->assertStatus(422)
            ->assertJsonPath('message', "The {$this->closed->label()} cutoff has already been settled for Jun Abad.");
    });

    it('only settles a real cutoff, and only once it has closed', function (): void {
        ($this->settle)([
            'start' => $this->closed->start->copy()->addDay()->toDateString(),
            'end' => $this->closed->end->toDateString(),
        ])->assertStatus(422);

        $open = $this->calendar->between(now(), now())[0];

        ($this->settle)([
            'start' => $open->start->toDateString(),
            'end' => $open->end->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', "The {$open->label()} cutoff has not closed yet.");
    });
});

describe('the transaction history', function (): void {
    it('lists each settlement with the cutoff it covers and how many runs it paid', function (): void {
        ($this->haul)(1_000_000, $this->previous->start->copy()->addDays(3));
        $run = ($this->haul)(500_000, $this->closed->start->copy()->addDays(2));
        ($this->haul)(400_000, $this->closed->start->copy()->addDays(5));
        ($this->fuelOnRun)($run, 100_000);

        expect(($this->history)())->toBe([]);

        ($this->settle)([
            'start' => $this->previous->start->toDateString(),
            'end' => $this->previous->end->toDateString(),
            'method' => 'cash',
            'cleared' => true,
            'reference' => 'CV-1',
        ])->assertCreated();

        ($this->settle)(['reference' => 'CV-2'])->assertCreated();

        $history = collect(($this->history)());

        expect($history)->toHaveCount(3)
            ->and($history->pluck('kind')->all())->toBe(['payout', 'adjustment', 'payout'])
            ->and($history[0]['amount_cents'])->toBe(-(440_000 + 352_000 - 100_000))
            ->and($history[0]['state'])->toBe('processing')
            ->and($history[0]['reference'])->toBe('CV-2')
            ->and($history[0]['period_label'])->toBe($this->closed->label())
            ->and($history[0]['runs_count'])->toBe(2)
            ->and($history[1]['amount_cents'])->toBe(-100_000)
            ->and($history[1]['state'])->toBe('charged')
            ->and($history[1]['description'])->toBe("Fuel ₱1,000.00 on {$this->closed->label()}")
            ->and($history[2]['amount_cents'])->toBe(-880_000)
            ->and($history[2]['state'])->toBe('paid')
            ->and($history[2]['method'])->toBe('cash')
            ->and($history[2]['period_label'])->toBe($this->previous->label())
            ->and($history[2]['runs_count'])->toBe(1);
    });
});

describe('when the costs swallow the share', function (): void {
    it('still charges the costs and leaves the owner owing the difference', function (): void {
        $run = ($this->haul)(200_000, $this->closed->start->copy()->addDays(2));
        ($this->fuelOnRun)($run, 300_000);

        $entry = ($this->settle)()->assertCreated()->json('data');

        expect($entry['kind'])->toBe('adjustment')
            ->and($entry['amount_cents'])->toBe(-300_000)
            ->and(WalletEntry::balanceFor($this->owner->getKey()))->toBe(-124_000);

        $body = ($this->payables)()->assertOk()->json('data');

        expect(($this->group)($body, 'truck_owners')['count'])->toBe(0)
            ->and($body['total_cents'])->toBe(0);

        expect(($this->cutoff)(($this->statement)(), $this->closed->start->toDateString())['state'])->toBe('paid');
    });
});

describe('who may do what', function (): void {
    it('lets the accountant read the statement and history but not pay', function (): void {
        $accountant = User::factory()->create(['role' => 'accountant']);

        ($this->haul)(1_000_000, $this->closed->start->copy()->addDays(2));

        $this->actingAs($accountant)->getJson('/api/v1/finance/payables/owners')->assertOk();
        $this->actingAs($accountant)->getJson("/api/v1/finance/payables/owners/{$this->owner->id}/history")->assertOk();

        $this->actingAs($accountant)
            ->postJson("/api/v1/finance/payables/owners/{$this->owner->id}/settle", [
                'start' => $this->closed->start->toDateString(),
                'end' => $this->closed->end->toDateString(),
            ])
            ->assertForbidden();
    });

    it('is closed to a dispatcher', function (): void {
        $dispatcher = User::factory()->create(['role' => 'dispatcher']);

        $this->actingAs($dispatcher)->getJson('/api/v1/finance/payables/owners')->assertForbidden();
        $this->actingAs($dispatcher)->getJson("/api/v1/finance/payables/owners/{$this->owner->id}/history")->assertForbidden();
    });
});
