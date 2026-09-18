<?php

declare(strict_types=1);

use App\Domain\Hr\Models\Contract;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * The rate card on a job, and the contract it opens on a hire.
 *
 * Two records with two different jobs to do, and most of what is asserted here
 * is the line between them.
 *
 * A **position** carries what the job pays: one basis, and a figure for each of
 * the three tiers somebody moves through. It is a price list, and it changes
 * when the firm decides a driver is worth more — which must have nothing to do
 * with what the drivers it already has are owed.
 *
 * A **contract** carries what one person is on, with the date it starts. It is
 * a row, not a column, so a rise appends and the figure it replaced is still
 * there afterwards.
 *
 * What these tests are defending:
 *
 *   **Opened, not referenced.** Hiring copies the tier's figure onto a
 *   contract; payroll reads the contract. Raising the driver rate for next
 *   year's hires must not silently restate what every existing driver is owed,
 *   including on a run somebody is halfway through checking. The same rule
 *   `employees.position` already follows for the job *title*, with money at
 *   stake instead of a label.
 *
 *   **A typed figure wins.** Somebody negotiated up keeps their number. The
 *   rate card fills in what the hire left blank and nothing else.
 *
 *   **A job nobody has priced opens nothing.** No contract at all, rather than
 *   one at ₱0.00 — which looks identical to a real one on every screen
 *   afterwards, and would put somebody on a run for nothing.
 *
 *   **Three tiers, not five.** Contractual and part-time are engagements rather
 *   than stages, and read the regular figure.
 *
 *   **Positions are one company's.** Two firms can both have a "Driver" on
 *   entirely different money and neither can see the other's.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->addPosition = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/access/positions', [
            'name' => 'Yard Marshal',
            'pay_basis' => 'monthly',
            'trainee_amount_cents' => 1_800_000,
            'probationary_amount_cents' => 2_100_000,
            'regular_amount_cents' => 2_400_000,
            ...$overrides,
        ]);

    $this->hire = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/employees', [
            'first_name' => 'Elena',
            'last_name' => 'Bautista',
            'position' => 'Yard Marshal',
            'contact' => '0917 555 0199',
            'hired_on' => '2026-01-05',
            // Stated, because the roster defaults a new hire to probationary
            // and most of what follows is about the card rather than the tier.
            // The tier tests below say so themselves.
            'employment_type' => 'regular',
            ...$overrides,
        ]);
});

describe('setting what a job pays', function (): void {
    it('keeps a figure for each tier on the position', function (): void {
        $position = ($this->addPosition)()->assertCreated()->json('data');

        expect($position['pay_basis'])->toBe('monthly')
            ->and($position['pay_basis_label'])->toBe('Monthly salary')
            ->and($position['pay_basis_unit'])->toBe('a month')
            ->and($position['trainee_amount_cents'])->toBe(1_800_000)
            ->and($position['probationary_amount_cents'])->toBe(2_100_000)
            ->and($position['regular_amount_cents'])->toBe(2_400_000)
            ->and($position['has_rate_card'])->toBeTrue();
    });

    it('reports a job nobody has priced as having no rate card', function (): void {
        // Which is every position the seeder creates: the system has never been
        // told what a dispatcher earns in this firm and must not invent one.
        $position = ($this->addPosition)([
            'name' => 'Dispatcher',
            'trainee_amount_cents' => 0,
            'probationary_amount_cents' => 0,
            'regular_amount_cents' => 0,
        ])->assertCreated()->json('data');

        expect($position['has_rate_card'])->toBeFalse();
    });

    it('prices a per-trip job by the haul', function (): void {
        // Per-trip pay used to hold no rate at all — the amount was whatever
        // the office had written in the day's driver column on the truck sheet.
        // It is a contract figure now, times the hauls delivered.
        $position = ($this->addPosition)([
            'name' => 'Trip Driver',
            'pay_basis' => 'per_trip',
            'trainee_amount_cents' => 150_000,
            'probationary_amount_cents' => 150_000,
            'regular_amount_cents' => 150_000,
        ])->assertCreated()->json('data');

        expect($position['pay_basis'])->toBe('per_trip')
            ->and($position['pay_basis_unit'])->toBe('a trip')
            ->and($position['has_rate_card'])->toBeTrue()
            ->and($position['pay_summary'])
            ->toBe('Each payslip pays ₱1,500 for every haul delivered in that cutoff.');
    });

    it('lets the office change it afterwards', function (): void {
        $position = ($this->addPosition)()->assertCreated()->json('data');

        $updated = $this->actingAs($this->admin)
            ->patchJson("/api/v1/access/positions/{$position['id']}", [
                'pay_basis' => 'daily',
                'regular_amount_cents' => 90_000,
            ])->assertOk()->json('data');

        expect($updated['pay_basis'])->toBe('daily')
            ->and($updated['regular_amount_cents'])->toBe(90_000);
    });
});

describe('hiring into it', function (): void {
    it('opens a contract on the tier the person is engaged at', function (): void {
        $position = ($this->addPosition)()->assertCreated()->json('data');

        $employee = ($this->hire)(['position_id' => $position['id']])
            ->assertCreated()->json('data');

        expect($employee['amount_cents'])->toBe(2_400_000)
            ->and($employee['pay_basis'])->toBe('monthly')
            ->and($employee['has_contract'])->toBeTrue()
            // Dated from the hire, not from today: a record entered a week late
            // still says the agreement started when the person did.
            ->and($employee['contract_effective_from'])->toBe('2026-01-05')
            // And the job title too, which this already did.
            ->and($employee['position'])->toBe('Yard Marshal');
    });

    it('puts a hire with nothing stated on the probationary figure', function (): void {
        // What the roster has always defaulted somebody to, and now the column
        // of the rate card that follows from it.
        $position = ($this->addPosition)()->assertCreated()->json('data');

        // Posted directly rather than through the helper, which states a tier.
        $employee = $this->actingAs($this->admin)->postJson('/api/v1/employees', [
            'first_name' => 'Elena',
            'last_name' => 'Bautista',
            'position_id' => $position['id'],
            'contact' => '0917 555 0199',
            'hired_on' => '2026-01-05',
        ])->assertCreated()->json('data');

        expect($employee['employment_type'])->toBe('probationary')
            ->and($employee['amount_cents'])->toBe(2_100_000);
    });

    it('pays a trainee the trainee figure and a probationer theirs', function (): void {
        $position = ($this->addPosition)()->assertCreated()->json('data');

        $trainee = ($this->hire)([
            'position_id' => $position['id'],
            'employment_type' => 'trainee',
        ])->assertCreated()->json('data');

        $probationary = ($this->hire)([
            'first_name' => 'Jun', 'last_name' => 'Santos',
            'position_id' => $position['id'],
            'employment_type' => 'probationary',
        ])->assertCreated()->json('data');

        expect($trainee['amount_cents'])->toBe(1_800_000)
            ->and($probationary['amount_cents'])->toBe(2_100_000);
    });

    it('pays a contractual or part-time hire the regular figure', function (): void {
        // Engagements rather than stages: a firm taking somebody on for a
        // season is not paying them a trainee's rate. Three columns, not five.
        $position = ($this->addPosition)()->assertCreated()->json('data');

        foreach (['contractual', 'part_time'] as $type) {
            $employee = ($this->hire)([
                'first_name' => 'Rosa', 'last_name' => ucfirst($type),
                'position_id' => $position['id'],
                'employment_type' => $type,
            ])->assertCreated()->json('data');

            expect($employee['amount_cents'])->toBe(2_400_000);
        }
    });

    it('opens a daily rate and its basis together', function (): void {
        $position = ($this->addPosition)([
            'name' => 'Yard Hand', 'pay_basis' => 'daily',
            'trainee_amount_cents' => 70_000,
            'probationary_amount_cents' => 78_000,
            'regular_amount_cents' => 85_000,
        ])->assertCreated()->json('data');

        $employee = ($this->hire)(['position_id' => $position['id']])
            ->assertCreated()->json('data');

        expect($employee['pay_basis'])->toBe('daily')
            ->and($employee['amount_cents'])->toBe(85_000)
            ->and($employee['paid_per_unit_worked'])->toBeTrue();
    });

    it('lets a typed figure win over the job’s', function (): void {
        $position = ($this->addPosition)()->assertCreated()->json('data');

        // Somebody negotiated up. The rate card fills in blanks, it does not
        // overrule the office.
        $employee = ($this->hire)([
            'position_id' => $position['id'],
            'amount_cents' => 3_000_000,
        ])->assertCreated()->json('data');

        expect($employee['amount_cents'])->toBe(3_000_000);
    });

    it('takes the basis from the job when only a figure is typed', function (): void {
        // The common case on the form: the office picks a job, which settles
        // whether this is a month, a day or a haul, and argues only about the
        // number.
        $position = ($this->addPosition)([
            'name' => 'Trip Driver', 'pay_basis' => 'per_trip',
            'trainee_amount_cents' => 150_000,
            'probationary_amount_cents' => 150_000,
            'regular_amount_cents' => 150_000,
        ])->assertCreated()->json('data');

        $employee = ($this->hire)([
            'position_id' => $position['id'],
            'amount_cents' => 180_000,
        ])->assertCreated()->json('data');

        expect($employee['pay_basis'])->toBe('per_trip')
            ->and($employee['amount_cents'])->toBe(180_000);
    });

    it('opens no contract on a deliberate zero', function (): void {
        $position = ($this->addPosition)()->assertCreated()->json('data');

        // Hired on nothing is somebody the office has not agreed a figure with
        // yet. No contract, which keeps them off pay runs — rather than one at
        // ₱0.00, which looks exactly like a real one afterwards.
        $employee = ($this->hire)([
            'position_id' => $position['id'],
            'amount_cents' => 0,
        ])->assertCreated()->json('data');

        expect($employee['has_contract'])->toBeFalse()
            ->and($employee['amount_cents'])->toBe(0)
            ->and($employee['pay_basis'])->toBeNull();
    });

    it('opens no contract when the job has no rate', function (): void {
        $position = ($this->addPosition)([
            'name' => 'Dispatcher',
            'trainee_amount_cents' => 0,
            'probationary_amount_cents' => 0,
            'regular_amount_cents' => 0,
        ])->assertCreated()->json('data');

        $employee = ($this->hire)(['position_id' => $position['id']])
            ->assertCreated()->json('data');

        expect($employee['has_contract'])->toBeFalse();
    });
});

/**
 * The reason it is a copy.
 */
describe('after the rate changes', function (): void {
    it('leaves everybody already hired exactly where they were', function (): void {
        $position = ($this->addPosition)()->assertCreated()->json('data');
        $employee = ($this->hire)(['position_id' => $position['id']])
            ->assertCreated()->json('data');

        // Next year's hires are offered more.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/access/positions/{$position['id']}", [
                'regular_amount_cents' => 3_600_000,
            ])->assertOk();

        $after = $this->actingAs($this->admin)
            ->getJson("/api/v1/employees/{$employee['id']}")->assertOk()->json('data');

        // A live reference would have restated what this person is owed —
        // silently, and possibly mid-payroll.
        expect($after['amount_cents'])->toBe(2_400_000);

        // And the next hire does get the new figure.
        $next = ($this->hire)([
            'first_name' => 'Jun', 'last_name' => 'Santos',
            'position_id' => $position['id'],
        ])->assertCreated()->json('data');

        expect($next['amount_cents'])->toBe(3_600_000);
    });
});

/**
 * Raising one person, which is the half the rate card cannot do.
 */
describe('a rise', function (): void {
    it('appends a row and leaves the one it replaced standing', function (): void {
        $position = ($this->addPosition)()->assertCreated()->json('data');
        $employee = ($this->hire)(['position_id' => $position['id']])
            ->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee['id']}", [
                'amount_cents' => 2_900_000,
                'effective_from' => '2026-07-01',
            ])->assertOk();

        $rows = Contract::query()
            ->where('employee_id', $employee['id'])
            ->orderBy('effective_from')
            ->get();

        // Two agreements, not one edited twice. What she was on last spring is
        // still answerable, which a column could never do.
        expect($rows)->toHaveCount(2)
            ->and($rows[0]->amount_cents)->toBe(2_400_000)
            ->and($rows[0]->effective_from->toDateString())->toBe('2026-01-05')
            ->and($rows[1]->amount_cents)->toBe(2_900_000)
            ->and($rows[1]->effective_from->toDateString())->toBe('2026-07-01');
    });

    it('does not pay until the day it starts', function (): void {
        $position = ($this->addPosition)()->assertCreated()->json('data');
        $employee = ($this->hire)(['position_id' => $position['id']])
            ->assertCreated()->json('data');

        // Agreed today, starting well into next year. Written down now because
        // that is when it was agreed, and paying nothing until it arrives.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee['id']}", [
                'amount_cents' => 3_300_000,
                'effective_from' => '2027-06-01',
            ])->assertOk();

        $after = $this->actingAs($this->admin)
            ->getJson("/api/v1/employees/{$employee['id']}")->assertOk()->json('data');

        expect($after['amount_cents'])->toBe(2_400_000);

        // And the row is there, waiting.
        expect(Contract::query()->where('employee_id', $employee['id'])->count())->toBe(2);
    });

    it('keeps the basis when only the figure moves', function (): void {
        $position = ($this->addPosition)([
            'name' => 'Yard Hand', 'pay_basis' => 'daily',
            'trainee_amount_cents' => 70_000,
            'probationary_amount_cents' => 78_000,
            'regular_amount_cents' => 85_000,
        ])->assertCreated()->json('data');

        $employee = ($this->hire)(['position_id' => $position['id']])
            ->assertCreated()->json('data');

        $raised = $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee['id']}", ['amount_cents' => 95_000])
            ->assertOk()->json('data');

        // A rise is a change of figure and nothing else. Moving somebody from a
        // day rate onto a trip rate is a different conversation.
        expect($raised['pay_basis'])->toBe('daily')
            ->and($raised['amount_cents'])->toBe(95_000);
    });
});

describe('moving somebody to another job', function (): void {
    it('takes the new job’s rate card when the edit names no figure', function (): void {
        $marshal = ($this->addPosition)()->assertCreated()->json('data');
        $hand = ($this->addPosition)([
            'name' => 'Yard Hand', 'pay_basis' => 'daily',
            'trainee_amount_cents' => 70_000,
            'probationary_amount_cents' => 78_000,
            'regular_amount_cents' => 85_000,
        ])->assertCreated()->json('data');

        $employee = ($this->hire)(['position_id' => $marshal['id']])
            ->assertCreated()->json('data');

        $moved = $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee['id']}", ['position_id' => $hand['id']])
            ->assertOk()->json('data');

        expect($moved['pay_basis'])->toBe('daily')
            ->and($moved['amount_cents'])->toBe(85_000);
    });

    it('does not undo a negotiated salary on an ordinary edit', function (): void {
        $position = ($this->addPosition)()->assertCreated()->json('data');

        $employee = ($this->hire)([
            'position_id' => $position['id'],
            'amount_cents' => 3_000_000,
        ])->assertCreated()->json('data');

        // Correcting a phone number must not reach for the position's rate, or
        // write a second contract for no reason.
        $edited = $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee['id']}", ['contact' => '0917 555 0200'])
            ->assertOk()->json('data');

        expect($edited['amount_cents'])->toBe(3_000_000)
            ->and(Contract::query()->where('employee_id', $employee['id'])->count())->toBe(1);
    });
});

/**
 * Per tenant, not centralised.
 *
 * Already true of the table — the tenancy migration gave `positions` a non-null
 * `company_id` and rescoped its `key` uniqueness to the pair — and asserted
 * here because it is now true of a rate card as well.
 */
describe('two companies', function (): void {
    it('lets each price the same job differently, and hides one from the other', function (): void {
        // `PositionSeeder` already gives every company a "Driver", so this
        // names a job neither has yet — otherwise the assertion below would
        // pick up the seeded row and prove nothing.
        $mineRow = ($this->addPosition)([
            'name' => 'Long Haul Driver', 'regular_amount_cents' => 2_400_000,
        ])->assertCreated()->json('data');

        $rival = $this->makeCompany('Rival Freight');
        app(CompanyProvisioner::class)->provision($rival);

        $theirs = $this->asCompany($rival, fn () => Position::create([
            'key' => 'long-haul-driver',
            'name' => 'Long Haul Driver',
            'pay_basis' => 'monthly',
            'regular_amount_cents' => 1_800_000,
        ]));

        // The same job title, on different money, in one install — and the
        // `key` is identical too, which the per-company unique index allows.
        expect($theirs->regular_amount_cents)->toBe(1_800_000);

        $mine = collect($this->actingAs($this->admin)
            ->getJson('/api/v1/access/positions')->assertOk()->json('data'));

        expect($mine->firstWhere('id', $mineRow['id'])['regular_amount_cents'])->toBe(2_400_000)
            // And the neighbour's row is not on this company's list at all.
            ->and($mine->pluck('id'))->not->toContain($theirs->id);
    });

    it('will not hire against another company’s position', function (): void {
        $rival = $this->makeCompany('Rival Freight');
        app(CompanyProvisioner::class)->provision($rival);

        $theirs = $this->asCompany($rival, fn () => Position::create([
            'key' => 'marshal-rival', 'name' => 'Yard Marshal', 'regular_amount_cents' => 1_800_000,
        ]));

        ($this->hire)(['position_id' => $theirs->id])->assertStatus(422);
    });

    it('keeps one firm’s contracts out of another’s', function (): void {
        $position = ($this->addPosition)()->assertCreated()->json('data');
        ($this->hire)(['position_id' => $position['id']])->assertCreated();

        $rival = $this->makeCompany('Rival Freight');
        app(CompanyProvisioner::class)->provision($rival);

        // A contract is scoped like everything else that describes a person, so
        // a neighbour counting them sees none of ours.
        $seen = $this->asCompany($rival, fn (): int => Contract::query()->count());

        expect($seen)->toBe(0)
            ->and(Contract::query()->count())->toBe(1);
    });
});

/**
 * The sentence an office reads instead of doing the arithmetic.
 *
 * "₱15,000 a month" is not what any single payslip says, and whether it is
 * halved at all depends on a cutoff set on a different screen. So the API says
 * it plainly — and says it from the calendar, so it cannot disagree with what
 * payroll will actually do.
 *
 * It speaks about the regular figure, because that is what the job pays
 * somebody who stays.
 */
describe('what the job comes to on a payslip', function (): void {
    it('splits a monthly salary across a twice-monthly cutoff', function (): void {
        $position = ($this->addPosition)(['regular_amount_cents' => 1_500_000])
            ->assertCreated()->json('data');

        expect($position['pay_summary'])
            ->toBe('₱15,000 a month — about ₱7,500 on each of 2 payslips.');
    });

    it('says one payslip where the firm pays monthly', function (): void {
        $this->actingAs($this->admin)
            ->patchJson('/api/v1/company', ['payroll_cutoff_days' => [31]])->assertOk();

        $position = ($this->addPosition)(['regular_amount_cents' => 1_500_000])
            ->assertCreated()->json('data');

        // Read off the firm's own calendar rather than assumed to be two — the
        // first thing a client dividing by two would get wrong.
        expect($position['pay_summary'])->toBe('₱15,000 a month, paid on one payslip.');
    });

    it('says a daily job pays by the day', function (): void {
        $position = ($this->addPosition)([
            'pay_basis' => 'daily', 'regular_amount_cents' => 85_000,
        ])->assertCreated()->json('data');

        expect($position['pay_summary'])
            ->toBe('Each payslip pays ₱850 for every day worked in that cutoff.');
    });

    it('says nothing has been set where nothing has', function (): void {
        $position = ($this->addPosition)([
            'name' => 'Dispatcher',
            'trainee_amount_cents' => 0,
            'probationary_amount_cents' => 0,
            'regular_amount_cents' => 0,
        ])->assertCreated()->json('data');

        expect($position['pay_summary'])
            ->toBe('No rate set yet — the figure is entered on each hire.');
    });
});
