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

/**
 * The split, and the account it accumulates in.
 *
 * Every run a partner hauls is split by the same percentage. What changes is
 * who is already holding the customer's money, and that single fact — recorded
 * as `trips.booking_source` — decides whether the wallet is credited or
 * charged:
 *
 *   `cargo_rush`  the haulier billed and collected  →  **+88%** to the partner
 *   `direct`      the partner billed and collected  →  **−12%** from them
 *
 * These tests hold the arithmetic in both directions, the once-only guarantee,
 * and the bounds on settling up. They are the tests most worth reading before
 * changing anything in `WalletService`: every one of them corresponds to a way
 * of being wrong that nobody would notice until a partner queried a figure.
 */
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

    /**
     * Take it, run it, hand it over — the whole of a partner's day.
     *
     * The offer is made here rather than at each call site. There is no open
     * board: a run only reaches a partner because a customer put their name on
     * it, and these tests are about what the money does afterwards rather than
     * about how the job arrived.
     */
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
        // ₱10,000 at 12% — the partner keeps the customer's money and owes the
        // haulier ₱1,200 of it. One row, negative.
        expect($this->entry->kind)->toBe(WalletEntryKind::Commission)
            ->and($this->entry->amount_cents)->toBe(-120_000)
            ->and($this->entry->source)->toBe(BookingSource::Direct)
            ->and($this->entry->gross_cents)->toBe(1_000_000)
            ->and($this->entry->rate_bp)->toBe(1200);

        expect(WalletEntry::query()->where('trip_id', $this->trip->getKey())->count())->toBe(1);
    });

    it('raises no invoice, because the customer has already paid somebody', function (): void {
        // The one failure that would reach the customer: billing them for a
        // haul they have already settled with the partner directly.
        expect(Invoice::query()->where('trip_id', $this->trip->getKey())->exists())->toBeFalse();

        // And it is still marked as having been through billing, so nothing
        // tries again later.
        expect($this->trip->refresh()->isBilled())->toBeTrue();
    });

    it('files nothing on a company truck sheet', function (): void {
        // The daily ledger is one company unit's costs and income. A partner's
        // truck has neither in this system, and a row for it would put
        // somebody else's diesel on the fleet's profitability page.
        expect(LedgerEntry::query()->where('trip_id', $this->trip->getKey())->exists())->toBeFalse();
    });

    it('leaves the partner owing the company', function (): void {
        $this->actingAs($this->user)
            ->getJson('/api/v1/partner/wallet')
            ->assertOk()
            ->assertJsonPath('data.balance_cents', -120_000)
            // Said in a word, so no client has to decide what a negative
            // balance means.
            ->assertJsonPath('data.standing', 'owed_to_company')
            // Reported as a magnitude: "commission charged: ₱1,200" is what a
            // person reads.
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
        // ₱10,000 billed, 12% kept, ₱8,800 owed onwards. One row, positive —
        // deliberately not a +8,800 beside a −1,200, which would net to 7,600
        // and be the kind of wrong that survives review.
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

        // Move the rate afterwards, where it is now moved: the settings card,
        // which is a PATCH on the company. The settled run must not follow it.
        $this->actingAs($this->admin)
            ->patchJson('/api/v1/company', ['trucker_commission_bp' => 500])
            ->assertOk();

        expect($trip->refresh()->commission_bp)->toBe(1200);
        expect(WalletEntry::query()->where('trip_id', $trip->getKey())->first()->amount_cents)
            ->toBe(-120_000);
    });

    it('splits every future run at the rate the office set', function (): void {
        // One standing rate for everybody the firm hauls with. It used to be
        // overridable per partner from a field on their own detail screen, and
        // that is gone — a commission is a commercial term the office states
        // once, not something re-typed beside an approve button.
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
        // ₱333.33 at 12% is 3,999.96 centavos. Truncating the haulier's cut
        // leaves the remainder with the person who did not write the software,
        // which is the only version of this nobody has to argue about.
        $trip = ($this->load)(33_333);
        ($this->haul)($trip);

        $entry = WalletEntry::query()->where('trip_id', $trip->getKey())->firstOrFail();

        expect($entry->amount_cents)->toBe(-3_999);
    });

    it('writes nothing at all for a run that billed nothing', function (): void {
        $trip = ($this->load)(0);
        ($this->haul)($trip);

        // A free haul splits to nothing, and a ₱0 row is noise on a statement
        // rather than a record of anything.
        expect(WalletEntry::query()->where('trip_id', $trip->getKey())->exists())->toBeFalse();
    });

    it('credits a run once, however many times the button is pressed', function (): void {
        $trip = ($this->load)(1_000_000);
        ($this->haul)($trip);

        // The office closing a run the partner already closed. `billed_at`
        // guards it, and the unique index on (trip_id, kind) is the belt to
        // those braces — the one figure a partner checks daily must not be
        // inflatable by pressing a button again.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$trip->id}/complete", ['receiver_name' => 'Mrs Uy'])
            ->assertStatus(422);

        expect(WalletEntry::query()->where('trip_id', $trip->getKey())->count())->toBe(1);
    });

    it('nets the two directions against each other', function (): void {
        // The ordinary partner: some weeks of the fleet work, some of their own.
        // One account rather than a payable and a receivable that never meet.
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

        // 880,000 − 120,000. Nobody writes a cheque in the other direction.
        $this->actingAs($this->user)
            ->getJson('/api/v1/partner/wallet')
            ->assertOk()
            ->assertJsonPath('data.balance_cents', 760_000)
            ->assertJsonPath('data.trips_settled', 2);
    });
});

describe('settling up', function (): void {
    /** Put the partner ₱8,800 in credit off one fleet run. */
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
            // The figure is derived from the runs, never typed.
            ->assertJsonPath('data.amount_cents', -880_000)
            // A transfer takes a day. Until it lands the partner is still owed
            // it — a bounced transfer has paid nobody, and a balance that had
            // already fallen would have to be corrected by hand.
            ->assertJsonPath('meta.balance_cents', 880_000);

        $earning = WalletEntry::query()->where('kind', WalletEntryKind::Earning->value)->firstOrFail();

        expect($earning->isSettled())->toBeTrue()
            ->and($earning->settled_at)->not->toBeNull()
            ->and($earning->settlement->reference)->toBe('BDO 449120')
            // Three states on a run, and this is the middle one.
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
        // The case that does not wait: money across a desk has arrived by the
        // time anybody types it.
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

        // Two figures, both true: still owed, and already sent. Folding them
        // together would say they had been paid before the transfer cleared.
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

        // And the balance did not move twice.
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

        // Confirming that your own payment arrived is not a thing the person
        // being paid should be able to assert — and the balance turns on it.
        $this->actingAs($this->user)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet/{$sent}/confirm")
            ->assertForbidden();
    });

    it('pays only the runs that were picked', function (): void {
        // A second run, so there is something to leave unpaid.
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

        // Part payment: one run now, the other on Friday. Both states recorded
        // rather than inferred from a total.
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

        // The whole point of the column. Pressing Pay twice on a statement
        // somebody left open used to hand the money over again.
        $pay()->assertStatus(422);

        expect(WalletEntry::query()->where('kind', WalletEntryKind::Payout->value)->count())->toBe(1);
    });

    it('refuses a payout with a typed figure', function (): void {
        // A desk sending both an amount and a set of runs has two ideas about
        // what is being paid; honouring the runs would hand over a different
        // sum from the one on screen.
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

        // Everything is settled now, so there is nothing left to pay for.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
                'kind' => WalletEntryKind::Payout->value,
            ])
            ->assertStatus(422);
    });

    it('reports what is still unpaid, separately from the balance', function (): void {
        // An adjustment moves the balance without being a run anybody can be
        // paid for, so the two figures diverge — and both are true.
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
        /**
         * The ordinary state of a busy partner: some of the fleet's work, some
         * of their own, and both sides owing at once.
         *
         * The net balance is positive — the fleet owes ₱8,800 and is owed
         * ₱1,200 — and it would be wrong to read that as "nothing to collect".
         * The two debts are settled separately and against different runs, so
         * what decides whether commission can be collected is whether any
         * commission run is outstanding, never the net.
         */
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

        // The commission run is cleared; the earning is untouched and still
        // waiting to be paid out.
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
        // Clear the credit in cash, so it lands at once and leaves a clean
        // account to put into deficit.
        $this->actingAs($this->admin)->postJson("/api/v1/truckers/{$this->trucker->id}/wallet", [
            'kind' => WalletEntryKind::Payout->value,
            'method' => 'cash',
            'cleared' => true,
        ])->assertCreated();

        ($this->haul)(($this->load)(1_000_000));

        // Cash across the front desk, so this one lands immediately too.
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
        // Every money system needs a way to fix what it got wrong. What to
        // avoid is a way to fix it without saying what was fixed.
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
        // A delivery is the only authority for what was hauled. A desk that
        // could write an earning could credit a run that never happened.
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

        // No id in the path, so there is nothing for a partner to change.
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

        // The point of showing a percentage at all is that both sides can check
        // it against the same rows.
        $this->actingAs($this->admin)
            ->getJson("/api/v1/truckers/{$this->trucker->id}/wallet")
            ->assertOk()
            ->assertJsonPath('data.balance_cents', -120_000)
            ->assertJsonPath('meta.commission_bp', 1200);
    });
});
