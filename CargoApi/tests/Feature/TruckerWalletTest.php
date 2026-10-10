<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\BookingSource;
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

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();

    $this->user = User::factory()->create([
        'role' => Role::Trucker->value,
        'company_id' => $this->company->getKey(),
    ]);

    $this->trucker = Trucker::factory()->approved()->create([
        'user_id' => $this->user->getKey(),
        'name' => 'Boyet Aquino',
    ]);

    TruckerVehicle::factory()->create(['trucker_id' => $this->trucker->getKey()]);
    $this->trucker->refresh()->load('vehicles');

    $this->load = fn (int $priceCents = 1_000_000, array $overrides = []) => Trip::create([
        'customer_id' => $this->customer->getKey(),
        'origin' => 'Iponan',
        'destination' => 'Bukidnon',
        'cargo' => 'Rice, 200 sacks',
        'weight_kg' => 10_000,
        'status' => StatusValue::Pending->value,
        'scheduled_at' => now()->addDay(),
        'price_cents' => $priceCents,
        'currency' => 'PHP',
        ...$overrides,
    ]);

    $this->haul = function (Trip $trip): void {
        $trip->update(['trucker_id' => $this->trucker->getKey()]);

        $this->actingAs($this->user)->postJson("/api/v1/partner/jobs/{$trip->id}/accept")->assertCreated();
        $this->actingAs($this->user)->postJson("/api/v1/partner/trips/{$trip->id}/start")->assertOk();
        $this->actingAs($this->user)
            ->postJson("/api/v1/partner/trips/{$trip->id}/deliver", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();
    };
});

describe('a run the customer gave the partner', function (): void {
    beforeEach(function (): void {
        $this->trip = ($this->load)(1_000_000);
        ($this->haul)($this->trip);
        $this->entry = WalletEntry::query()->where('trip_id', $this->trip->getKey())->firstOrFail();
    });

    it('charges the commission and nothing else', function (): void {
        expect($this->entry->kind)->toBe(WalletEntryKind::Commission)
            ->and($this->entry->amount_cents)->toBe(-120_000)
            ->and($this->entry->source)->toBe(BookingSource::Direct)
            ->and($this->entry->gross_cents)->toBe(1_000_000)
            ->and($this->entry->rate_bp)->toBe(1200);

        expect(WalletEntry::query()->where('trip_id', $this->trip->getKey())->count())->toBe(1);
    });

    it('raises no invoice, because the customer has already paid somebody', function (): void {
        expect(Invoice::query()->where('trip_id', $this->trip->getKey())->exists())->toBeFalse();

        expect($this->trip->refresh()->isBilled())->toBeTrue();
    });

    it('files nothing on a company truck sheet', function (): void {
        expect(LedgerEntry::query()->where('trip_id', $this->trip->getKey())->exists())->toBeFalse();
    });

    it('leaves the partner owing the company', function (): void {
        $this->actingAs($this->user)
            ->getJson('/api/v1/partner/wallet')
            ->assertOk()
            ->assertJsonPath('data.balance_cents', -120_000)
            ->assertJsonPath('data.standing', 'owed_to_company')
            ->assertJsonPath('data.commission_cents', 120_000)
            ->assertJsonPath('data.earned_cents', 0);
    });
});

describe('a run the desk handed out', function (): void {
    beforeEach(function (): void {
        $this->trip = ($this->load)(1_000_000);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$this->trip->id}/assign-trucker", [
                'trucker_id' => $this->trucker->getKey(),
            ])
            ->assertOk();

        $this->actingAs($this->user)->postJson("/api/v1/partner/trips/{$this->trip->id}/start")->assertOk();
        $this->actingAs($this->user)
            ->postJson("/api/v1/partner/trips/{$this->trip->id}/deliver", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();

        $this->entry = WalletEntry::query()->where('trip_id', $this->trip->getKey())->firstOrFail();
    });

    it('credits the partner their share, not the gross', function (): void {
        expect($this->entry->kind)->toBe(WalletEntryKind::Earning)
            ->and($this->entry->amount_cents)->toBe(880_000)
            ->and($this->entry->source)->toBe(BookingSource::CargoRush)
            ->and($this->entry->gross_cents)->toBe(1_000_000);

        expect(WalletEntry::query()->where('trip_id', $this->trip->getKey())->count())->toBe(1);
    });

    it('still bills the customer, because the haulier is collecting', function (): void {
        expect(Invoice::query()->where('trip_id', $this->trip->getKey())->exists())->toBeTrue();
    });

    it('leaves the company owing the partner', function (): void {
        $this->actingAs($this->user)
            ->getJson('/api/v1/partner/wallet')
            ->assertOk()
            ->assertJsonPath('data.balance_cents', 880_000)
            ->assertJsonPath('data.standing', 'owed_to_trucker');
    });
});

describe('the arithmetic', function (): void {
    it('freezes the rate onto the trip, so a renegotiation cannot restate history', function (): void {
        $trip = ($this->load)(1_000_000);
        ($this->haul)($trip);

        expect($trip->refresh()->commission_bp)->toBe(1200)
            ->and($trip->commission_cents)->toBe(120_000);

        $this->actingAs($this->admin)
            ->patchJson('/api/v1/company', ['trucker_commission_bp' => 500])
            ->assertOk();

        expect($trip->refresh()->commission_bp)->toBe(1200);
        expect(WalletEntry::query()->where('trip_id', $trip->getKey())->first()->amount_cents)
            ->toBe(-120_000);
    });

    it('splits every future run at the rate the office set', function (): void {
        $this->actingAs($this->admin)
            ->patchJson('/api/v1/company', ['trucker_commission_bp' => 800])
            ->assertOk()
            ->assertJsonPath('data.rates.trucker_commission_bp', 800);

        $trip = ($this->load)(1_000_000);
        ($this->haul)($trip);

        expect(WalletEntry::query()->where('trip_id', $trip->getKey())->first()->amount_cents)
            ->toBe(-80_000);
    });

    it('gives the odd centavo to the partner, not the platform', function (): void {
        $trip = ($this->load)(33_333);
        ($this->haul)($trip);

        $entry = WalletEntry::query()->where('trip_id', $trip->getKey())->firstOrFail();

        expect($entry->amount_cents)->toBe(-3_999);
    });

    it('writes nothing at all for a run that billed nothing', function (): void {
        $trip = ($this->load)(0);
        ($this->haul)($trip);

        expect(WalletEntry::query()->where('trip_id', $trip->getKey())->exists())->toBeFalse();
    });

    it('credits a run once, however many times the button is pressed', function (): void {
        $trip = ($this->load)(1_000_000);
        ($this->haul)($trip);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/complete", ['receiver_name' => 'Mrs Uy'])
            ->assertStatus(422);

        expect(WalletEntry::query()->where('trip_id', $trip->getKey())->count())->toBe(1);
    });

    it('nets the two directions against each other', function (): void {
        $own = ($this->load)(1_000_000);
        ($this->haul)($own);

        $fleet = ($this->load)(1_000_000);
        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$fleet->id}/assign-trucker", ['trucker_id' => $this->trucker->getKey()])
            ->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/partner/trips/{$fleet->id}/start")->assertOk();
        $this->actingAs($this->user)
            ->postJson("/api/v1/partner/trips/{$fleet->id}/deliver", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();

        $this->actingAs($this->user)
            ->getJson('/api/v1/partner/wallet')
            ->assertOk()
            ->assertJsonPath('data.balance_cents', 760_000)
            ->assertJsonPath('data.trips_settled', 2);
    });
});

describe('settling up', function (): void {
    beforeEach(function (): void {
        $trip = ($this->load)(1_000_000);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/assign-trucker", ['trucker_id' => $this->trucker->getKey()])
            ->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/partner/trips/{$trip->id}/start")->assertOk();
        $this->actingAs($this->user)
            ->postJson("/api/v1/partner/trips/{$trip->id}/deliver", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();
    });

    it('sends the money, and leaves it owed until it lands', function (): void {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Payout->value,
                'reference' => 'BDO 449120',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount_cents', -880_000)
            ->assertJsonPath('meta.balance_cents', 880_000);

        $earning = WalletEntry::query()->where('kind', WalletEntryKind::Earning->value)->firstOrFail();

        expect($earning->isSettled())->toBeTrue()
            ->and($earning->settled_at)->not->toBeNull()
            ->and($earning->settlement->reference)->toBe('BDO 449120')
            ->and($earning->paymentState())->toBe('processing');
    });

    it('drops the balance when the payment is confirmed', function (): void {
        $sent = $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Payout->value,
                'reference' => 'BDO 449120',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet/{$sent}/confirm")
            ->assertOk()
            ->assertJsonPath('meta.balance_cents', 0);

        $earning = WalletEntry::query()->where('kind', WalletEntryKind::Earning->value)->firstOrFail();

        expect($earning->refresh()->paymentState())->toBe('paid');
    });

    it('lands straight away when the cash was handed over', function (): void {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Payout->value,
                'method' => 'cash',
                'cleared' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('meta.balance_cents', 0);

        expect(WalletEntry::query()->where('kind', WalletEntryKind::Earning->value)->first()->paymentState())
            ->toBe('paid');
    });

    it('reports what is on its way beside what is owed', function (): void {
        $this->actingAs($this->admin)->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
            'kind' => WalletEntryKind::Payout->value,
        ])->assertCreated();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/truckers/{$this->trucker->id}/wallet")
            ->assertOk()
            ->assertJsonPath('data.balance_cents', 880_000)
            ->assertJsonPath('data.in_flight_cents', 880_000);
    });

    it('will not confirm the same payment twice', function (): void {
        $sent = $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Payout->value,
            ])
            ->json('data.id');

        $confirm = fn () => $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet/{$sent}/confirm");

        $confirm()->assertOk();
        $confirm()->assertStatus(422);

        expect(WalletEntry::balanceFor($this->trucker->getKey()))->toBe(0);
    });

    it('will not confirm a payment on somebody else account', function (): void {
        $other = Trucker::factory()->approved()->create(['licence_no' => null]);

        $sent = $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Payout->value,
            ])
            ->json('data.id');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$other->id}/wallet/{$sent}/confirm")
            ->assertNotFound();
    });

    it('will not let the trucker confirm their own payment', function (): void {
        $sent = $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Payout->value,
            ])
            ->json('data.id');

        $this->actingAs($this->user)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet/{$sent}/confirm")
            ->assertForbidden();
    });

    it('pays only the runs that were picked', function (): void {
        $second = ($this->load)(500_000);
        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$second->id}/assign-trucker", ['trucker_id' => $this->trucker->getKey()])
            ->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/partner/trips/{$second->id}/start")->assertOk();
        $this->actingAs($this->user)
            ->postJson("/api/v1/partner/trips/{$second->id}/deliver", ['receiver_name' => 'Mrs Uy'])
            ->assertOk();

        $first = WalletEntry::query()->where('trip_id', $this->trip ?? null)->first()
            ?? WalletEntry::query()->where('kind', WalletEntryKind::Earning->value)->orderBy('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Payout->value,
                'entry_ids' => [$first->getKey()],
                'reference' => 'BDO 1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount_cents', -$first->amount_cents);

        expect($first->refresh()->isSettled())->toBeTrue();

        $stillOwed = WalletEntry::query()
            ->where('kind', WalletEntryKind::Earning->value)
            ->whereNull('settled_by')
            ->get();

        expect($stillOwed)->toHaveCount(1)
            ->and($stillOwed->first()->getKey())->not->toBe($first->getKey());
    });

    it('will not pay the same run twice', function (): void {
        $earning = WalletEntry::query()->where('kind', WalletEntryKind::Earning->value)->firstOrFail();

        $pay = fn () => $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Payout->value,
                'entry_ids' => [$earning->getKey()],
            ]);

        $pay()->assertCreated();

        $pay()->assertStatus(422);

        expect(WalletEntry::query()->where('kind', WalletEntryKind::Payout->value)->count())->toBe(1);
    });

    it('refuses a payout with a typed figure', function (): void {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Payout->value,
                'amount_cents' => 900_000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount_cents');

        expect(WalletEntry::balanceFor($this->trucker->getKey()))->toBe(880_000);
    });

    it('refuses to pay out when nothing is outstanding', function (): void {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Payout->value,
            ])
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Payout->value,
            ])
            ->assertStatus(422);
    });

    it('reports what is still unpaid, separately from the balance', function (): void {
        $this->actingAs($this->admin)->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
            'kind' => WalletEntryKind::Adjustment->value,
            'amount_cents' => -5_000,
            'note' => 'Damaged pallet',
        ])->assertCreated();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/truckers/{$this->trucker->id}/wallet")
            ->assertOk()
            ->assertJsonPath('data.balance_cents', 875_000)
            ->assertJsonPath('data.unpaid_earnings_cents', 880_000)
            ->assertJsonPath('data.unpaid_earnings_count', 1);
    });

    it('collects commission even while the fleet owes them more', function (): void {
        ($this->haul)(($this->load)(1_000_000));

        expect(WalletEntry::balanceFor($this->trucker->getKey()))->toBe(760_000);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Remittance->value,
                'method' => 'cash',
                'cleared' => true,
                'reference' => 'Cash, front desk',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount_cents', 120_000);

        expect(WalletEntry::query()->where('kind', WalletEntryKind::Commission->value)->first()->paymentState())
            ->toBe('paid')
            ->and(WalletEntry::query()->where('kind', WalletEntryKind::Earning->value)->first()->paymentState())
            ->toBe('unpaid');
    });

    it('refuses a remittance from somebody who owes nothing', function (): void {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Remittance->value,
            ])
            ->assertStatus(422);
    });

    it('takes a remittance against a debt running the other way', function (): void {
        $this->actingAs($this->admin)->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
            'kind' => WalletEntryKind::Payout->value,
            'method' => 'cash',
            'cleared' => true,
        ])->assertCreated();

        ($this->haul)(($this->load)(1_000_000));

        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Remittance->value,
                'method' => 'cash',
                'cleared' => true,
                'reference' => 'Cash, front desk',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount_cents', 120_000)
            ->assertJsonPath('meta.balance_cents', 0);
    });

    it('insists an adjustment says what it is for', function (): void {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Adjustment->value,
                'amount_cents' => -5_000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
    });

    it('lets an adjustment move the balance either way, with a reason', function (): void {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Adjustment->value,
                'amount_cents' => -5_000,
                'note' => 'Damaged pallet, agreed by phone',
            ])
            ->assertCreated()
            ->assertJsonPath('meta.balance_cents', 875_000);
    });

    it('will not let the desk hand-write an earning', function (): void {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Earning->value,
                'amount_cents' => 500_000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('kind');
    });

    it('records who at the desk moved the money', function (): void {
        $this->actingAs($this->admin)->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
            'kind' => WalletEntryKind::Payout->value,
        ])->assertCreated();

        $entry = WalletEntry::query()->where('kind', WalletEntryKind::Payout->value)->firstOrFail();

        expect($entry->recorded_by)->toBe($this->admin->getKey());
    });
});

describe('who may read a wallet', function (): void {
    it('shows a partner their own and nobody else', function (): void {
        $other = User::factory()->create([
            'role' => Role::Trucker->value,
            'company_id' => $this->company->getKey(),
        ]);
        $otherTrucker = Trucker::factory()->approved()->create(['user_id' => $other->getKey()]);
        TruckerVehicle::factory()->create(['trucker_id' => $otherTrucker->getKey()]);

        ($this->haul)(($this->load)(1_000_000));

        $this->actingAs($other)
            ->getJson('/api/v1/partner/wallet')
            ->assertOk()
            ->assertJsonPath('data.balance_cents', 0);
    });

    it('will not let a partner settle their own account', function (): void {
        $this->actingAs($this->user)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Payout->value,
                'amount_cents' => 100,
            ])
            ->assertForbidden();
    });

    it('shows the office the same figures the partner sees', function (): void {
        ($this->haul)(($this->load)(1_000_000));

        $this->actingAs($this->admin)
            ->getJson("/api/v1/truckers/{$this->trucker->id}/wallet")
            ->assertOk()
            ->assertJsonPath('data.balance_cents', -120_000)
            ->assertJsonPath('meta.commission_bp', 1200);
    });
});
