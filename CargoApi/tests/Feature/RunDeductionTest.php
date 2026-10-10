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
            ->and($after['component_deductions_cents'])->toBe(200_000)
            ->and($after['net_cents'])->toBe($before['net_cents'] - 200_000)
            ->and($after['gross_cents'])->toBe($before['gross_cents']);
    });

    it('takes the name from the catalogue when one is picked', function (): void {
        ($this->hire)();
        $uniform = ($this->component)();
        $run = ($this->open)()->assertCreated()->json('data');

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

        expect($line['net_cents'])->toBe($line['gross_cents'] - $line['deductions_cents'])
            ->and($line['net_cents'])->toBe($before['net_cents'] - 200_000);
    });

    it('does not duplicate it across two rebuilds', function (): void {
        ($this->hire)();
        $run = ($this->open)()->assertCreated()->json('data');

        ($this->charge)($run, ['name' => 'Uniform', 'amount_cents' => 200_000])->assertCreated();

        ($this->rebuild)($run);
        $line = ($this->rebuild)($run)['lines'][0];

        expect(collect($line['components'])->where('name', 'Uniform'))->toHaveCount(1)
            ->and($line['component_deductions_cents'])->toBe(200_000);
    });

    it('still recomputes the assigned ones', function (): void {
        $employee = ($this->hire)();
        $uniform = ($this->component)(['name' => 'Rice allowance', 'kind' => 'earning']);
        ($this->assign)($employee['id'], $uniform['id']);

        $run = ($this->open)()->assertCreated()->json('data');
        ($this->charge)($run, ['name' => 'Breakage', 'amount_cents' => 50_000])->assertCreated();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/payroll/components/{$uniform['id']}", ['amount_cents' => 400_000])
            ->assertOk();

        $line = ($this->rebuild)($run)['lines'][0];

        expect(collect($line['components'])->firstWhere('name', 'Rice allowance')['amount_cents'])
            ->toBe(200_000)
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

        ($this->charge)($run, ['name' => 'Uniform', 'amount_cents' => 200_000])
            ->assertStatus(422);
    });
});
