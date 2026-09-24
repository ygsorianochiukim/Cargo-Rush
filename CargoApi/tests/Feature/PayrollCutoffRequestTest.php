<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Payroll\Models\PayrollCutoffRequest;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * Asking for the pay cutoff to be moved, when you cannot move it yourself.
 *
 * What these tests are defending:
 *
 *   **The split is the feature.** Filing is `payroll.manage` and deciding is
 *   `company.manage`, and the accountant who runs every pay run holds only the
 *   first. A request endpoint that let the asker approve their own request
 *   would be a way *round* the permission rather than a way to exercise it, so
 *   both halves of that are asserted.
 *
 *   **Approving applies it.** The cutoff actually moves, in the same act. An
 *   approval that only marked a row would have put the retyping — and the typo
 *   — back exactly where this exists to remove it from.
 *
 *   **A request may be filed while a draft run is open; it may not be approved
 *   then.** That asymmetry is deliberate and is the one piece of this that
 *   looks like a bug until you read why: the office notices the cutoff is wrong
 *   *while running payroll on it*, which is precisely when a draft exists.
 *
 *   **One at a time.** Two pending requests is a disagreement inside the
 *   office, not a queue — and an administrator approving one without seeing the
 *   other would be settling an argument they did not know was happening.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    /** Holds `payroll.manage` and not `company.manage` — the person who asks. */
    $this->accountant = User::where('email', 'accounts@cargorush.ph')->firstOrFail();
    $this->driver = User::where('email', 'marco@cargorush.ph')->firstOrFail();

    $this->ask = fn (array $overrides = [], ?User $as = null) => $this->actingAs($as ?? $this->accountant)
        ->postJson('/api/v1/payroll/cutoff-requests', [
            'cutoff_days' => [10, 25],
            'reason' => 'The yard moved its working week and the fortnight no longer matches.',
            ...$overrides,
        ]);

    $this->hire = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/employees', [
            'first_name' => 'Elena',
            'last_name' => 'Bautista',
            'position' => 'Office Staff',
            'contact' => '0917 555 0199',
            'hired_on' => '2026-01-05',
            'amount_cents' => 3_000_000,
            ...$overrides,
        ])->assertCreated()->json('data');

    $this->openDraft = fn () => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll', [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'pay_date' => '2026-09-15',
        ])->assertCreated()->json('data');
});

describe('filing one', function (): void {
    it('lets whoever runs payroll ask, and says what they asked for', function (): void {
        $filed = ($this->ask)()->assertCreated()->json('data');

        expect($filed['status'])->toBe('pending')
            ->and($filed['cutoff_days'])->toBe([10, 25])
            ->and($filed['requested_by_name'])->toBe($this->accountant->name)
            ->and($filed['summary'])
            ->toBe('Payroll runs twice a month, cut off on the 10th and the 25th.')
            // What it would mean, worked out — because "10, 25" is not
            // something an administrator can picture well enough to say yes to.
            ->and($filed['calendar']['example_periods'][0]['label'])->toBeString()
            ->and($filed['current_calendar']['cutoff_days'])->toBe([15, 31]);
    });

    it('stores the days ascending however they were sent', function (): void {
        expect(($this->ask)(['cutoff_days' => [25, 10]])->assertCreated()->json('data.cutoff_days'))
            ->toBe([10, 25]);
    });

    it('insists on a reason', function (): void {
        ($this->ask)(['reason' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0',
                'Say why the cutoff should move — whoever decides this is not in the room.');
    });

    it('applies the same shape rules as the settings form', function (): void {
        // Four is past what anything downstream is built for; three is a
        // real calendar now and is checked separately below.
        ($this->ask)(['cutoff_days' => [5, 10, 20, 31]])->assertStatus(422);
        ($this->ask)(['cutoff_days' => [28, 31]])->assertStatus(422);
        ($this->ask)(['cutoff_days' => [15, 15]])->assertStatus(422);
    });

    it('takes a request for three cutoffs a month', function (): void {
        ($this->ask)(['cutoff_days' => [5, 15, 25]])->assertCreated();
    });

    it('refuses a second while one is still waiting', function (): void {
        ($this->ask)()->assertCreated();

        ($this->ask)(['cutoff_days' => [5, 20]])
            ->assertStatus(422)
            ->assertJsonPath('message', sprintf(
                'There is already a request waiting — Payroll runs twice a month, cut off on the 10th and the 25th. '
                .'Asked for by %s. Withdraw it before filing another, so nobody is deciding between two.',
                $this->accountant->name,
            ));
    });

    it('tells the administrators there is something to decide', function (): void {
        ($this->ask)()->assertCreated();

        $note = NotificationItem::query()
            ->where('user_id', $this->admin->id)
            ->where('title', 'Payroll cutoff change requested')
            ->first();

        expect($note)->not->toBeNull()
            ->and($note->detail)->toContain('the 10th and the 25th')
            ->and($note->detail)->toContain($this->accountant->name);
    });

    it('refuses a driver outright', function (): void {
        ($this->ask)([], $this->driver)->assertForbidden();
    });
});

/**
 * The asymmetry worth reading the reason for.
 */
describe('while a draft run is open', function (): void {
    it('still lets somebody file the request', function (): void {
        ($this->hire)();
        ($this->openDraft)();

        // The office notices the cutoff is wrong *while running payroll on it*.
        // Refusing here would mean the only moment the problem is visible is
        // the one moment it cannot be reported.
        ($this->ask)()->assertCreated();
    });

    it('will not let it be approved, and says which run is in the way', function (): void {
        ($this->hire)();
        $run = ($this->openDraft)();
        $filed = ($this->ask)()->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/cutoff-requests/{$filed['id']}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', sprintf(
                '%s is still a draft for 1–15 Sep 2026. Approve it or delete it before moving the cutoff — '
                .'otherwise it would be paid on a period that no longer exists.',
                $run['reference'],
            ));

        // And the screen knows before anybody presses it.
        $pending = $this->actingAs($this->admin)
            ->getJson('/api/v1/payroll/cutoff-requests/pending')->assertOk()->json('data');

        expect($pending['blocked_reason'])->toContain($run['reference']);
    });

    it('becomes approvable once that run is out of the way', function (): void {
        ($this->hire)();
        $run = ($this->openDraft)();
        $filed = ($this->ask)()->assertCreated()->json('data');

        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/cutoff-requests/{$filed['id']}/approve")
            ->assertOk();
    });
});

describe('deciding one', function (): void {
    it('moves the cutoff when approved — that is what approving is', function (): void {
        $filed = ($this->ask)()->assertCreated()->json('data');

        $decided = $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/cutoff-requests/{$filed['id']}/approve", ['note' => 'Agreed with the yard.'])
            ->assertOk()->json('data');

        expect($decided['status'])->toBe('approved')
            ->and($decided['decided_by_name'])->toBe($this->admin->name)
            ->and($decided['decision_note'])->toBe('Agreed with the yard.')
            // What they were on before, kept — otherwise the log says a change
            // happened without saying from what.
            ->and($decided['previous_cutoff_days'])->toBe([15, 31]);

        // The company actually moved.
        expect($this->company->refresh()->payroll_cutoff_days)->toBe([10, 25]);

        // And so did the periods payroll offers.
        $periods = $this->actingAs($this->admin)
            ->getJson('/api/v1/payroll/periods?month=2026-09')->assertOk()->json('data');

        expect($periods[0]['start'])->toBe('2026-08-26')
            ->and($periods[0]['end'])->toBe('2026-09-10');
    });

    it('changes nothing when declined, and says why', function (): void {
        $filed = ($this->ask)()->assertCreated()->json('data');

        $decided = $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/cutoff-requests/{$filed['id']}/decline", [
                'note' => 'Stay on the 15th until the BIR filing is done.',
            ])->assertOk()->json('data');

        expect($decided['status'])->toBe('declined')
            ->and($decided['decision_note'])->toBe('Stay on the 15th until the BIR filing is done.')
            ->and($this->company->refresh()->payroll_cutoff_days)->toBeNull();
    });

    it('carries the deduction schedule too, where the request asked for one', function (): void {
        $filed = ($this->ask)(['payroll_deduct_on' => 'second'])->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/cutoff-requests/{$filed['id']}/approve")->assertOk();

        expect($this->company->refresh()->payroll_deduct_on->value)->toBe('second');
    });

    it('leaves the deduction schedule alone where the request did not mention it', function (): void {
        $this->actingAs($this->admin)
            ->patchJson('/api/v1/company', ['payroll_deduct_on' => 'first'])->assertOk();

        $filed = ($this->ask)()->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/cutoff-requests/{$filed['id']}/approve")->assertOk();

        // Not quietly reset to the default by an approval nobody asked to
        // touch it.
        expect($this->company->refresh()->payroll_deduct_on->value)->toBe('first');
    });

    it('tells the person who asked what happened', function (): void {
        $filed = ($this->ask)()->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/cutoff-requests/{$filed['id']}/approve")->assertOk();

        expect(NotificationItem::query()
            ->where('user_id', $this->accountant->id)
            ->where('title', 'Cutoff change approved')
            ->exists())->toBeTrue();
    });

    it('refuses to decide the same request twice', function (): void {
        $filed = ($this->ask)()->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/cutoff-requests/{$filed['id']}/approve")->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/cutoff-requests/{$filed['id']}/decline")
            ->assertStatus(422)
            ->assertJsonPath('message', 'That request was already approved. File a new one rather than reopening it.');
    });
});

/**
 * The permission split, which is the whole reason this endpoint exists.
 */
describe('who may do which half', function (): void {
    it('does not let the asker approve their own request', function (): void {
        $filed = ($this->ask)()->assertCreated()->json('data');

        // They hold `payroll.manage` and filed it. Deciding is `company.manage`
        // — the permission that could have made the change directly, which is
        // exactly why approving cannot be looser than it.
        $this->actingAs($this->accountant)
            ->postJson("/api/v1/payroll/cutoff-requests/{$filed['id']}/approve")
            ->assertForbidden();

        $this->actingAs($this->accountant)
            ->postJson("/api/v1/payroll/cutoff-requests/{$filed['id']}/decline")
            ->assertForbidden();

        expect($this->company->refresh()->payroll_cutoff_days)->toBeNull();
    });

    it('lets the asker read it and take it back', function (): void {
        $filed = ($this->ask)()->assertCreated()->json('data');

        // They need to see what happened to it — a decision that only appeared
        // on the administrator's screen would leave the office running payroll
        // on a cutoff they had asked to change.
        $this->actingAs($this->accountant)
            ->getJson('/api/v1/payroll/cutoff-requests')->assertOk()->assertJsonCount(1, 'data');

        $withdrawn = $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/payroll/cutoff-requests/{$filed['id']}")
            ->assertOk()->json('data');

        // Withdrawn, not deleted and not declined: a request somebody thought
        // better of is a different fact from one an administrator refused.
        expect($withdrawn['status'])->toBe('withdrawn')
            ->and(PayrollCutoffRequest::query()->count())->toBe(1);

        // And the way is clear to file another.
        ($this->ask)(['cutoff_days' => [5, 20]])->assertCreated();
    });

    it('offers nothing to a driver', function (): void {
        ($this->ask)()->assertCreated();

        $this->actingAs($this->driver)->getJson('/api/v1/payroll/cutoff-requests')->assertForbidden();
    });
});

describe('when the change was already made by hand', function (): void {
    it('says so rather than inviting a second identical approval', function (): void {
        $filed = ($this->ask)()->assertCreated()->json('data');

        expect($filed['already_in_force'])->toBeFalse();

        // The administrator goes and sets it directly, leaving the request
        // sitting there looking like outstanding work.
        $this->actingAs($this->admin)
            ->patchJson('/api/v1/company', ['payroll_cutoff_days' => [10, 25]])->assertOk();

        $pending = $this->actingAs($this->admin)
            ->getJson('/api/v1/payroll/cutoff-requests/pending')->assertOk()->json('data');

        expect($pending['already_in_force'])->toBeTrue()
            ->and($pending['blocked_reason'])->toBeNull();
    });

    it('answers with nothing when there is no request waiting', function (): void {
        expect($this->actingAs($this->admin)
            ->getJson('/api/v1/payroll/cutoff-requests/pending')->assertOk()->json('data'))
            ->toBe([]);
    });
});
