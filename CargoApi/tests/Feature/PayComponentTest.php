<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\EmployeePayComponent;
use App\Domain\Payroll\Models\PayComponent;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Models\PayRunLineComponent;
use App\Domain\Shared\Enums\StatusValue;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * Salary structure: the allowances and deductions a firm pays every period.
 *
 * What these tests are defending:
 *
 *   **The office stops retyping.** Before this, a rice allowance was typed onto
 *   every payslip of every employee every fortnight. Assigning it once has to
 *   put it on every run afterwards, at the right amount, with no further
 *   action — that is the whole feature and the first two tests are it.
 *
 *   **A payslip stays a payslip.** The itemised lines are frozen copies, so
 *   renaming a component, changing its amount, or deleting it outright must not
 *   touch a run that has already been built. This is the same rule the employee
 *   name and the basic already follow, and it is the one most worth a test
 *   because a join would have been shorter to write.
 *
 *   **The hand-typed figures survive a rebuild.** Components total into their
 *   own columns beside `allowance_cents`, never into it. A rebuild recomputes
 *   the components and must leave somebody's typed correction alone.
 *
 *   **The tax follows a taxable allowance and not a de minimis one.** The BIR's
 *   order is contributions first, then tax on what is left — and the
 *   contributions themselves come off the monthly basic, which an allowance
 *   does not move.
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
            'department' => 'Administration',
            'contact' => '0917 555 0199',
            'hired_on' => '2026-01-05',
            'amount_cents' => 3_000_000,
            ...$overrides,
        ])->assertCreated()->json('data');

    /** Add a component to the firm's catalogue. */
    $this->component = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll/components', [
            'name' => 'Rice allowance',
            'kind' => 'earning',
            'basis' => 'fixed',
            'amount_cents' => 200_000,
            'schedule' => 'monthly_split',
            ...$overrides,
        ]);

    /** Put somebody on one. */
    $this->assign = fn (string $employeeId, string $componentId, array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll/assignments', [
            'employee_id' => $employeeId,
            'pay_component_id' => $componentId,
            'effective_from' => '2026-01-01',
            ...$overrides,
        ]);

    $this->open = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll', [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'pay_date' => '2026-09-15',
            ...$overrides,
        ]);
});

describe('paying an allowance without retyping it', function (): void {
    it('puts an assigned component on the payslip, itemised', function (): void {
        $employee = ($this->hire)();
        $component = ($this->component)()->assertCreated()->json('data');
        ($this->assign)($employee['id'], $component['id'])->assertCreated();

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        // A ₱2,000 monthly allowance, split across the month's two payslips.
        expect($line['component_earnings_cents'])->toBe(100_000)
            ->and($line['components'])->toHaveCount(1)
            ->and($line['components'][0]['name'])->toBe('Rice allowance')
            ->and($line['components'][0]['kind'])->toBe('earning')
            ->and($line['components'][0]['amount_cents'])->toBe(100_000)
            // And it is in the gross, on top of half the monthly basic.
            ->and($line['basic_cents'])->toBe(1_500_000)
            ->and($line['gross_cents'])->toBe(1_600_000);
    });

    it('carries it on every run afterwards, untouched', function (): void {
        $employee = ($this->hire)();
        $component = ($this->component)()->assertCreated()->json('data');
        ($this->assign)($employee['id'], $component['id'])->assertCreated();

        // Three consecutive fortnights, and nobody typed anything.
        foreach ([
            ['2026-09-01', '2026-09-15'],
            ['2026-09-16', '2026-09-30'],
            ['2026-10-01', '2026-10-15'],
        ] as [$start, $end]) {
            $line = ($this->open)(['period_start' => $start, 'period_end' => $end, 'pay_date' => $end])
                ->assertCreated()->json('data.lines.0');

            expect($line['component_earnings_cents'])->toBe(100_000);
        }
    });

    it('takes a deduction off rather than adding it on', function (): void {
        $employee = ($this->hire)();
        $loan = ($this->component)([
            'name' => 'SSS loan',
            'kind' => 'deduction',
            'amount_cents' => 50_000,
            'schedule' => 'each_run',
        ])->assertCreated()->json('data');

        ($this->assign)($employee['id'], $loan['id'])->assertCreated();

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['component_deductions_cents'])->toBe(50_000)
            ->and($line['component_earnings_cents'])->toBe(0)
            // On the deductions side of the payslip, not netted off the gross.
            ->and($line['gross_cents'])->toBe(1_500_000)
            ->and($line['net_cents'])->toBe($line['gross_cents'] - $line['deductions_cents']);
    });

    it('works out a percentage of the basic, and follows a rise on its own', function (): void {
        $employee = ($this->hire)();
        $cola = ($this->component)([
            'name' => 'COLA',
            'basis' => 'percent_of_basic',
            'amount_cents' => 0,
            // 10% of the monthly basic.
            'rate_bp' => 1000,
            'schedule' => 'monthly_split',
        ])->assertCreated()->json('data');

        ($this->assign)($employee['id'], $cola['id'])->assertCreated();

        // 10% of ₱30,000 is ₱3,000 a month, ₱1,500 on this payslip.
        expect(($this->open)()->assertCreated()->json('data.lines.0.component_earnings_cents'))
            ->toBe(150_000);

        // A rise, and nothing about the component or the assignment changes.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee['id']}", ['amount_cents' => 4_000_000])
            ->assertOk();

        expect(($this->open)(['period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'pay_date' => '2026-10-15'])
            ->assertCreated()->json('data.lines.0.component_earnings_cents'))
            ->toBe(200_000);
    });
});

describe('which payslip carries it', function (): void {
    beforeEach(function (): void {
        $this->employee = ($this->hire)();

        $this->onBoth = fn (string $schedule) => collect([
            ['2026-09-01', '2026-09-15'],
            ['2026-09-16', '2026-09-30'],
        ])->map(fn (array $period) => ($this->open)([
            'period_start' => $period[0], 'period_end' => $period[1], 'pay_date' => $period[1],
        ])->assertCreated()->json('data.lines.0.component_earnings_cents'));
    });

    it('splits a monthly figure, odd centavo on the second', function (): void {
        // ₱1,000.01 a month — where a careless halving loses a centavo.
        $component = ($this->component)(['amount_cents' => 100_001, 'schedule' => 'monthly_split'])
            ->assertCreated()->json('data');
        ($this->assign)($this->employee['id'], $component['id'])->assertCreated();

        $amounts = ($this->onBoth)('monthly_split');

        expect($amounts->all())->toBe([50_000, 50_001])
            ->and($amounts->sum())->toBe(100_001);
    });

    it('loads a monthly figure onto whichever cutoff the office picked', function (): void {
        $component = ($this->component)(['schedule' => 'second_cutoff'])->assertCreated()->json('data');
        ($this->assign)($this->employee['id'], $component['id'])->assertCreated();

        expect(($this->onBoth)('second_cutoff')->all())->toBe([0, 200_000]);
    });

    it('pays a per-payslip figure in full on both', function (): void {
        // The case the statutory schedule has no equivalent of: ₱2,000 a
        // payslip is ₱4,000 a month, and that is what the office meant.
        $component = ($this->component)(['schedule' => 'each_run'])->assertCreated()->json('data');
        ($this->assign)($this->employee['id'], $component['id'])->assertCreated();

        expect(($this->onBoth)('each_run')->all())->toBe([200_000, 200_000]);
    });

    it('pays the whole monthly figure on the single run of a monthly payroll', function (): void {
        config(['cargo.payroll.runs_per_month' => 1]);

        $component = ($this->component)(['schedule' => 'monthly_split'])->assertCreated()->json('data');
        ($this->assign)($this->employee['id'], $component['id'])->assertCreated();

        // No second payslip for the other half to land on.
        expect(($this->open)(['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'pay_date' => '2026-09-30'])
            ->assertCreated()->json('data.lines.0.component_earnings_cents'))
            ->toBe(200_000);
    });
});

describe('when an assignment applies', function (): void {
    it('skips a period before it starts and after it ends', function (): void {
        $employee = ($this->hire)();
        $component = ($this->component)(['schedule' => 'each_run'])->assertCreated()->json('data');

        ($this->assign)($employee['id'], $component['id'], [
            'effective_from' => '2026-09-16',
            'effective_to' => '2026-09-30',
        ])->assertCreated();

        $before = ($this->open)(['period_start' => '2026-09-01', 'period_end' => '2026-09-15'])
            ->assertCreated()->json('data.lines.0');
        $during = ($this->open)(['period_start' => '2026-09-16', 'period_end' => '2026-09-30', 'pay_date' => '2026-09-30'])
            ->assertCreated()->json('data.lines.0');
        $after = ($this->open)(['period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'pay_date' => '2026-10-15'])
            ->assertCreated()->json('data.lines.0');

        expect($before['component_earnings_cents'])->toBe(0)
            ->and($during['component_earnings_cents'])->toBe(200_000)
            ->and($after['component_earnings_cents'])->toBe(0);
    });

    it('pays on the period it starts in, without prorating', function (): void {
        $employee = ($this->hire)();
        $component = ($this->component)(['schedule' => 'each_run'])->assertCreated()->json('data');

        // Granted on the 10th, mid-period. The person had it for part of the
        // fortnight and is paid the allowance for that fortnight — an office
        // pays it, it does not divide it by days.
        ($this->assign)($employee['id'], $component['id'], ['effective_from' => '2026-09-10'])
            ->assertCreated();

        expect(($this->open)()->assertCreated()->json('data.lines.0.component_earnings_cents'))
            ->toBe(200_000);
    });

    it('leaves out an inactive assignment and an inactive component', function (): void {
        $employee = ($this->hire)();

        $paused = ($this->component)(['name' => 'Paused allowance'])->assertCreated()->json('data');
        $retired = ($this->component)(['name' => 'Retired allowance'])->assertCreated()->json('data');

        $assignment = ($this->assign)($employee['id'], $paused['id'])->assertCreated()->json('data');
        ($this->assign)($employee['id'], $retired['id'])->assertCreated();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/payroll/assignments/{$assignment['id']}", ['status' => 'inactive'])
            ->assertOk();
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/payroll/components/{$retired['id']}", ['status' => 'inactive'])
            ->assertOk();

        expect(($this->open)()->assertCreated()->json('data.lines.0.component_earnings_cents'))->toBe(0);
    });

    it('uses this person\'s override rather than the catalogue figure', function (): void {
        $employee = ($this->hire)();
        $component = ($this->component)(['schedule' => 'each_run'])->assertCreated()->json('data');

        ($this->assign)($employee['id'], $component['id'], ['amount_cents' => 350_000])->assertCreated();

        expect(($this->open)()->assertCreated()->json('data.lines.0.component_earnings_cents'))
            ->toBe(350_000);
    });
});

describe('what the tax does', function (): void {
    it('leaves a de minimis allowance out of the tax base', function (): void {
        $employee = ($this->hire)();
        $plain = ($this->open)()->assertCreated()->json('data.lines.0');

        $rice = ($this->component)(['schedule' => 'each_run'])->assertCreated()->json('data');
        ($this->assign)($employee['id'], $rice['id'])->assertCreated();

        $withAllowance = ($this->open)()->assertCreated()->json('data.lines.0');

        // Non-taxable by default, which is the honest default: rice, uniform
        // and medical allowances are de minimis benefits up to the BIR's
        // ceilings, and that is most of what a fleet pays on top of a basic.
        expect($withAllowance['withholding_tax_cents'])->toBe($plain['withholding_tax_cents'])
            ->and($withAllowance['component_earnings_cents'])->toBe(200_000);
    });

    it('taxes a taxable one, and still leaves the contributions alone', function (): void {
        $employee = ($this->hire)();
        $plain = ($this->open)()->assertCreated()->json('data.lines.0');

        $taxable = ($this->component)([
            'name' => 'Taxable allowance', 'schedule' => 'each_run', 'taxable' => true,
        ])->assertCreated()->json('data');
        ($this->assign)($employee['id'], $taxable['id'])->assertCreated();

        $withAllowance = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($withAllowance['withholding_tax_cents'])->toBeGreaterThan($plain['withholding_tax_cents'])
            // SSS, PhilHealth and Pag-IBIG come off the monthly *basic*, which
            // an allowance does not move — that is what the agencies' own
            // schedules read.
            ->and($withAllowance['sss_cents'])->toBe($plain['sss_cents'])
            ->and($withAllowance['philhealth_cents'])->toBe($plain['philhealth_cents'])
            ->and($withAllowance['pagibig_cents'])->toBe($plain['pagibig_cents']);
    });

    it('never treats a deduction as taxable, whatever the flag says', function (): void {
        $employee = ($this->hire)();
        $component = ($this->component)([
            'name' => 'Canteen', 'kind' => 'deduction', 'taxable' => true, 'schedule' => 'each_run',
        ])->assertCreated()->json('data');

        // The flag is meaningless on a deduction and is reported as false
        // rather than forbidden, so a form switching kind need not hide it.
        expect($component['taxable'])->toBeFalse();

        ($this->assign)($employee['id'], $component['id'])->assertCreated();

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['components'][0]['taxable'])->toBeFalse();
    });
});

/**
 * The reason the itemised rows are a table and not a join.
 */
describe('a payslip keeps saying what it said', function (): void {
    it('is untouched when the component is renamed, repriced or deleted', function (): void {
        $employee = ($this->hire)();
        $component = ($this->component)(['schedule' => 'each_run'])->assertCreated()->json('data');
        ($this->assign)($employee['id'], $component['id'])->assertCreated();

        $run = ($this->open)()->assertCreated()->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();

        // The office tidies its catalogue afterwards.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/payroll/components/{$component['id']}", [
                'name' => 'Rice subsidy', 'amount_cents' => 500_000,
            ])->assertOk();

        $after = $this->actingAs($this->admin)
            ->getJson("/api/v1/payroll/{$run['id']}")->assertOk()->json('data.lines.0');

        expect($after['components'][0]['name'])->toBe('Rice allowance')
            ->and($after['components'][0]['amount_cents'])->toBe(200_000)
            ->and($after['component_earnings_cents'])->toBe(200_000);
    });

    it('survives the component being removed entirely', function (): void {
        $employee = ($this->hire)();
        $component = ($this->component)(['schedule' => 'each_run'])->assertCreated()->json('data');
        $assignment = ($this->assign)($employee['id'], $component['id'])->assertCreated()->json('data');

        $run = ($this->open)()->assertCreated()->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();

        // Unassign first so the component is genuinely deletable.
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/payroll/assignments/{$assignment['id']}")->assertNoContent();
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/payroll/components/{$component['id']}")->assertNoContent();

        $after = $this->actingAs($this->admin)
            ->getJson("/api/v1/payroll/{$run['id']}")->assertOk()->json('data.lines.0');

        expect($after['components'])->toHaveCount(1)
            ->and($after['components'][0]['name'])->toBe('Rice allowance')
            ->and($after['components'][0]['amount_cents'])->toBe(200_000)
            ->and($after['component_earnings_cents'])->toBe(200_000);

        // Gone from the catalogue, which is what the office asked for...
        expect(PayComponent::query()->find($component['id']))->toBeNull()
            // ...and the payslip does not read anything through it anyway. The
            // delete is a *soft* one, like every other delete in this codebase,
            // so the row is still there behind the scope and the foreign key
            // never fires — which is precisely why the payslip carries its own
            // copy rather than relying on the key's `nullOnDelete`.
            ->and(PayRunLineComponent::query()
                ->where('pay_run_line_id', $after['id'])->first()?->component)
            ->toBeNull();
    });
});

describe('rebuilding a run', function (): void {
    it('recomputes the components and leaves a typed allowance alone', function (): void {
        $employee = ($this->hire)();
        $component = ($this->component)(['schedule' => 'each_run'])->assertCreated()->json('data');
        ($this->assign)($employee['id'], $component['id'])->assertCreated();

        $run = ($this->open)()->assertCreated()->json('data');

        // Somebody types a one-off allowance onto this payslip by hand.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/payroll/{$run['id']}/lines/{$run['lines'][0]['id']}", [
                'allowance_cents' => 75_000,
                'adjustment_note' => 'Typhoon assistance',
            ])->assertOk();

        // A rebuild throws the lines away and works them out again — and the
        // components land in their own columns, so this is not the collision
        // it would have been had they shared `allowance_cents`.
        $rebuilt = $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/rebuild")->assertOk()->json('data.lines.0');

        expect($rebuilt['component_earnings_cents'])->toBe(200_000)
            // A rebuild is documented as replacing the lines wholesale, so the
            // hand-typed figure goes with them — what matters is that it was
            // never *merged into* or *doubled by* the component total.
            ->and($rebuilt['allowance_cents'])->toBe(0)
            ->and($rebuilt['gross_cents'])->toBe(1_500_000 + 200_000);
    });

    it('does not leave the old itemised rows behind', function (): void {
        $employee = ($this->hire)();
        $component = ($this->component)(['schedule' => 'each_run'])->assertCreated()->json('data');
        ($this->assign)($employee['id'], $component['id'])->assertCreated();

        $run = ($this->open)()->assertCreated()->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/rebuild")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/rebuild")->assertOk();

        $line = $this->actingAs($this->admin)
            ->getJson("/api/v1/payroll/{$run['id']}")->assertOk()->json('data.lines.0');

        // One row, not three. The lines are deleted wholesale on a rebuild and
        // the itemised rows cascade with them.
        expect($line['components'])->toHaveCount(1)
            ->and($line['component_earnings_cents'])->toBe(200_000);
    });

    it('keeps the totals and the itemised rows agreeing', function (): void {
        $employee = ($this->hire)();

        foreach ([
            ['name' => 'Rice allowance', 'kind' => 'earning', 'amount_cents' => 200_000],
            ['name' => 'COLA', 'kind' => 'earning', 'amount_cents' => 150_000],
            ['name' => 'Canteen', 'kind' => 'deduction', 'amount_cents' => 30_000],
        ] as $spec) {
            $component = ($this->component)([...$spec, 'schedule' => 'each_run'])->assertCreated()->json('data');
            ($this->assign)($employee['id'], $component['id'])->assertCreated();
        }

        $run = ($this->open)()->assertCreated()->json('data');

        $line = PayRunLine::query()->with('components')->findOrFail($run['lines'][0]['id']);

        expect($line->componentTotalsAgree())->toBeTrue()
            ->and($line->isConsistent())->toBeTrue()
            ->and($line->component_earnings_cents)->toBe(350_000)
            ->and($line->component_deductions_cents)->toBe(30_000);
    });
});

describe('keeping the catalogue', function (): void {
    it('refuses a fixed component with no amount, and a percentage with no rate', function (): void {
        ($this->component)(['amount_cents' => 0])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount_cents.0',
                'A fixed component needs an amount. Use a percentage basis to set it as a share of the basic.');

        ($this->component)(['name' => 'COLA', 'basis' => 'percent_of_basic', 'amount_cents' => 0])
            ->assertStatus(422)
            ->assertJsonPath('errors.rate_bp.0',
                'A percentage component needs a rate. 450 is 4.5% of the monthly basic.');
    });

    it('refuses a second component with the same name', function (): void {
        ($this->component)()->assertCreated();
        ($this->component)()->assertStatus(422);
    });

    it('lets another firm use the same name', function (): void {
        ($this->component)()->assertCreated();

        $rival = $this->makeCompany('Rival Freight');

        // The index is on the pair, so "Rice allowance" is one firm's to name.
        $theirs = $this->asCompany($rival, fn () => PayComponent::create([
            'name' => 'Rice allowance', 'kind' => 'earning', 'amount_cents' => 100_000,
        ]));

        expect($theirs->exists)->toBeTrue()
            ->and($theirs->company_id)->toBe($rival->id);
    });

    it('retires a component in use rather than deleting it', function (): void {
        $employee = ($this->hire)();
        $component = ($this->component)()->assertCreated()->json('data');
        ($this->assign)($employee['id'], $component['id'])->assertCreated();

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/v1/payroll/components/{$component['id']}")
            ->assertOk();

        expect($response->json('meta.retired'))->toBeTrue()
            ->and($response->json('data.status'))->toBe(StatusValue::Inactive->value)
            // Still there, and so is the assignment — which is the point: a
            // delete that cascaded would have taken the assignment with it.
            ->and(PayComponent::query()->find($component['id']))->not->toBeNull()
            ->and(EmployeePayComponent::query()->count())->toBe(1);
    });

    it('deletes one nobody is assigned', function (): void {
        $component = ($this->component)()->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/payroll/components/{$component['id']}")
            ->assertNoContent();

        expect(PayComponent::query()->find($component['id']))->toBeNull();
    });

    it('counts who is on each component', function (): void {
        $employee = ($this->hire)();
        $component = ($this->component)()->assertCreated()->json('data');
        ($this->assign)($employee['id'], $component['id'])->assertCreated();

        expect($this->actingAs($this->admin)->getJson('/api/v1/payroll/components')
            ->assertOk()->json('data.0.assignment_count'))->toBe(1);
    });

    it('will not assign a component belonging to another firm', function (): void {
        $employee = ($this->hire)();

        $rival = $this->makeCompany('Rival Freight');
        $theirs = $this->asCompany($rival, fn () => PayComponent::create([
            'name' => 'Their allowance', 'kind' => 'earning', 'amount_cents' => 100_000,
        ]));

        // `exists` queries the table outside the tenant scope, so without the
        // company clause on the rule this would create a dangling assignment.
        ($this->assign)($employee['id'], $theirs->id)
            ->assertStatus(422)
            ->assertJsonPath('errors.pay_component_id.0', 'That is not a component on this firm’s list.');
    });
});

describe('reading what somebody is paid', function (): void {
    it('lists a person\'s assignments with the component on each', function (): void {
        $employee = ($this->hire)();
        $component = ($this->component)()->assertCreated()->json('data');
        ($this->assign)($employee['id'], $component['id'], ['amount_cents' => 250_000])->assertCreated();

        $row = $this->actingAs($this->admin)
            ->getJson("/api/v1/employees/{$employee['id']}/pay-components")
            ->assertOk()->json('data.0');

        expect($row['component']['name'])->toBe('Rice allowance')
            ->and($row['overrides_amount'])->toBeTrue()
            ->and($row['effective_amount_cents'])->toBe(250_000);
    });

    it('reports no peso figure for a percentage component', function (): void {
        $employee = ($this->hire)();
        $cola = ($this->component)([
            'name' => 'COLA', 'basis' => 'percent_of_basic', 'amount_cents' => 0, 'rate_bp' => 1000,
        ])->assertCreated()->json('data');
        ($this->assign)($employee['id'], $cola['id'])->assertCreated();

        $row = $this->actingAs($this->admin)
            ->getJson("/api/v1/employees/{$employee['id']}/pay-components")
            ->assertOk()->json('data.0');

        // Null rather than zero: there is no peso answer without a salary, and
        // ₱0.00 would read as a component that pays nothing.
        expect($row['effective_amount_cents'])->toBeNull()
            ->and($row['effective_rate_bp'])->toBe(1000);
    });
});
