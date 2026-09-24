<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayRunLineComponent;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * Deductions added to one payslip, on the run itself.
 *
 * The charge nobody is going to set up an assignment for: a uniform issued this
 * fortnight, a breakage, a cash advance against this payslip and no other. The
 * salary structure answers the recurring case — assign it once, it lands every
 * period — and it is the wrong shape entirely for a one-off.
 *
 * Before this there was exactly one place to put such a thing: a single
 * `other_deductions_cents` figure with one free-text note. Two charges in the
 * same fortnight had to be added up in somebody's head, and the payslip said
 * "₱3,450 other", which is a number the person holding it cannot ask a question
 * about.
 *
 * What these tests are defending:
 *
 *   **It is named on the payslip.** Itemised beside the assigned ones, in the
 *   same list, because the person reading it does not care where a deduction
 *   came from — only what it was for.
 *
 *   **It survives a rebuild.** This is the one that matters. Rebuilding throws
 *   every line away and works them out again, and a hand-added row has nothing
 *   to recompute it from. An office that loses a ₱2,000 uniform by pressing
 *   *Work it out again* stops trusting the screen — worse than the missing
 *   figure. So they are carried across, and carried across **into the same
 *   arithmetic**, not stapled on after the totals are settled.
 *
 *   **Deductions only.** A taxable earning belongs in the tax base, and the
 *   withholding on this line was worked out when the run was built. Adding one
 *   here would leave the tax saying something the gross no longer supports.
 *
 *   **An assigned row cannot be picked off here.** Removing it on one payslip
 *   would be a correction the next rebuild silently undoes. The office ends the
 *   assignment instead, which is the change that lasts.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

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

    $this->component = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll/components', [
            'name' => 'Uniform',
            'kind' => 'deduction',
            'basis' => 'fixed',
            'amount_cents' => 50_000,
            'schedule' => 'monthly_split',
            ...$overrides,
        ])->assertCreated()->json('data');

    $this->assign = fn (string $employeeId, string $componentId) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll/assignments', [
            'employee_id' => $employeeId,
            'pay_component_id' => $componentId,
            'effective_from' => '2026-01-01',
        ])->assertCreated();

    $this->open = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll', [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'pay_date' => '2026-09-15',
            ...$overrides,
        ]);

    $this->charge = fn (array $run, array $body) => $this->actingAs($this->admin)
        ->postJson("/api/v1/payroll/{$run['id']}/lines/{$run['lines'][0]['id']}/deductions", $body);

    $this->rebuild = fn (array $run) => $this->actingAs($this->admin)
        ->postJson("/api/v1/payroll/{$run['id']}/rebuild")->assertOk()->json('data');
});

describe('putting one on a payslip', function (): void {
    it('names it, takes it off the net, and says it was added by hand', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');
        $before = $run['lines'][0];

        $after = ($this->charge)($run, ['name' => 'Uniform', 'amount_cents' => 200_000])
            ->assertCreated()->json('data.lines.0');

        $row = collect($after['components'])->firstWhere('name', 'Uniform');

        expect($row)->not->toBeNull()
            ->and($row['kind'])->toBe('deduction')
            ->and($row['amount_cents'])->toBe(200_000)
            ->and($row['added_by_hand'])->toBeTrue()
            // The figure it adds up into, and the net that follows from it.
            ->and($after['component_deductions_cents'])->toBe(200_000)
            ->and($after['net_cents'])->toBe($before['net_cents'] - 200_000)
            // The gross is untouched: a deduction is not pay.
            ->and($after['gross_cents'])->toBe($before['gross_cents']);
    });

    it('takes the name from the catalogue when one is picked', function (): void {
        ($this->hire)();
        $uniform = ($this->component)();
        $run = ($this->open)()->assertCreated()->json('data');

        // The amount is this payslip's, whatever the catalogue says the
        // component is normally worth — ₱500 there, ₱2,000 charged here.
        $line = ($this->charge)($run, [
            'pay_component_id' => $uniform['id'],
            'amount_cents' => 200_000,
        ])->assertCreated()->json('data.lines.0');

        $row = collect($line['components'])->firstWhere('name', 'Uniform');

        expect($row['amount_cents'])->toBe(200_000)
            ->and($row['pay_component_id'])->toBe($uniform['id']);
    });

    it('itemises several rather than summing them into one figure', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');

        ($this->charge)($run, ['name' => 'Uniform', 'amount_cents' => 200_000])->assertCreated();
        $line = ($this->charge)($run, ['name' => 'Breakage', 'amount_cents' => 145_000])
            ->assertCreated()->json('data.lines.0');

        // Two rows, not "₱3,450 other" — which is the entire point.
        expect(collect($line['components'])->pluck('name')->all())
            ->toBe(['Uniform', 'Breakage'])
            ->and($line['component_deductions_cents'])->toBe(345_000);
    });

    it('refuses one with no name and nothing picked', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');

        ($this->charge)($run, ['amount_cents' => 200_000])->assertStatus(422);
    });

    it('refuses an earning', function (): void {
        ($this->hire)();
        $bonus = ($this->component)(['name' => 'Bonus', 'kind' => 'earning']);
        $run = ($this->open)()->assertCreated()->json('data');

        // The tax on this line was worked out when the run was built. An
        // earning added afterwards would leave it saying something the gross no
        // longer supports.
        ($this->charge)($run, ['pay_component_id' => $bonus['id'], 'amount_cents' => 100_000])
            ->assertStatus(422);
    });
});

describe('working the run out again', function (): void {
    it('carries a hand-added deduction across the rebuild', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');

        ($this->charge)($run, ['name' => 'Uniform', 'amount_cents' => 200_000])->assertCreated();

        $rebuilt = ($this->rebuild)($run);
        $line = $rebuilt['lines'][0];
        $row = collect($line['components'])->firstWhere('name', 'Uniform');

        // The row that has nothing behind it to recompute from is exactly the
        // row a rebuild would otherwise drop, silently.
        expect($row)->not->toBeNull()
            ->and($row['amount_cents'])->toBe(200_000)
            ->and($row['added_by_hand'])->toBeTrue()
            ->and($line['component_deductions_cents'])->toBe(200_000);
    });

    it('carries it into the arithmetic, not onto the end of it', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');
        $before = $run['lines'][0];

        ($this->charge)($run, ['name' => 'Uniform', 'amount_cents' => 200_000])->assertCreated();

        $line = ($this->rebuild)($run)['lines'][0];

        // The totals on a rebuilt payslip are written once, at insert, from the
        // same list the catalogue's components arrive in. A carried-over row
        // stapled on afterwards would show here as a net that does not follow
        // from its own parts.
        expect($line['net_cents'])->toBe($line['gross_cents'] - $line['deductions_cents'])
            ->and($line['net_cents'])->toBe($before['net_cents'] - 200_000);
    });

    it('does not duplicate it across two rebuilds', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');

        ($this->charge)($run, ['name' => 'Uniform', 'amount_cents' => 200_000])->assertCreated();

        ($this->rebuild)($run);
        $line = ($this->rebuild)($run)['lines'][0];

        // Carried across, not accumulated. The obvious way to write this
        // feature doubles the charge every time somebody presses the button.
        expect(collect($line['components'])->where('name', 'Uniform'))->toHaveCount(1)
            ->and($line['component_deductions_cents'])->toBe(200_000);
    });

    it('still recomputes the assigned ones', function (): void {
        $employee = ($this->hire)();
        $uniform = ($this->component)(['name' => 'Rice allowance', 'kind' => 'earning']);
        ($this->assign)($employee['id'], $uniform['id']);

        $run = ($this->open)()->assertCreated()->json('data');
        ($this->charge)($run, ['name' => 'Breakage', 'amount_cents' => 50_000])->assertCreated();

        // The catalogue's amount moves, which is what a rebuild is *for*.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/payroll/components/{$uniform['id']}", ['amount_cents' => 400_000])
            ->assertOk();

        $line = ($this->rebuild)($run)['lines'][0];

        expect(collect($line['components'])->firstWhere('name', 'Rice allowance')['amount_cents'])
            ->toBe(200_000)
            // And the hand-added one is untouched beside it.
            ->and(collect($line['components'])->firstWhere('name', 'Breakage')['amount_cents'])
            ->toBe(50_000);
    });
});

describe('taking one back off', function (): void {
    it('removes it and puts the net back', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');
        $before = $run['lines'][0];

        $added = ($this->charge)($run, ['name' => 'Uniform', 'amount_cents' => 200_000])
            ->assertCreated()->json('data.lines.0');

        $row = collect($added['components'])->firstWhere('name', 'Uniform');

        $line = $this->actingAs($this->admin)
            ->deleteJson("/api/v1/payroll/{$run['id']}/lines/{$before['id']}/deductions/{$row['id']}")
            ->assertOk()->json('data.lines.0');

        expect($line['components'])->toBeEmpty()
            ->and($line['component_deductions_cents'])->toBe(0)
            ->and($line['net_cents'])->toBe($before['net_cents']);
    });

    it('will not pick an assigned one off a single payslip', function (): void {
        $employee = ($this->hire)();
        $uniform = ($this->component)();
        ($this->assign)($employee['id'], $uniform['id']);

        $run = ($this->open)()->assertCreated()->json('data');
        $line = $run['lines'][0];
        $row = collect($line['components'])->firstWhere('name', 'Uniform');

        expect($row['added_by_hand'])->toBeFalse();

        // Removing it here would be a correction the next rebuild silently
        // undoes. The office ends the assignment instead.
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/payroll/{$run['id']}/lines/{$line['id']}/deductions/{$row['id']}")
            ->assertStatus(422);

        expect(PayRunLineComponent::query()->count())->toBe(1);
    });
});

describe('once the run is closed', function (): void {
    it('refuses to add to an approved run', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();

        // Payslips may already be out. The figures on an approved run are
        // frozen, and this is one more way of editing them.
        ($this->charge)($run, ['name' => 'Uniform', 'amount_cents' => 200_000])
            ->assertStatus(422);
    });
});
