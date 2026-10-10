<?php

declare(strict_types=1);

use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\StoreCredit;
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
            'department' => 'Administration',
            'contact' => '0917 555 0199',
            'hired_on' => '2026-01-05',
            'amount_cents' => 3_000_000,
            'sss_enrolled' => true,
            'philhealth_enrolled' => true,
            'pagibig_enrolled' => true,
            ...$overrides,
        ])->assertCreated()->json('data');

    $this->charge = fn (string $employeeId, array $overrides = []) => $this->actingAs($this->admin)
        ->postJson("/api/v1/employees/{$employeeId}/store-credits", [
            'kind' => 'charge',
            'amount_cents' => 50_000,
            'description' => '3 kg rice',
            'outlet' => 'Mini mart',
            'charged_on' => '2026-09-05',
            ...$overrides,
        ]);

    $this->tab = fn (string $employeeId) => $this->actingAs($this->admin)
        ->getJson("/api/v1/employees/{$employeeId}/store-credits");

    $this->open = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll', [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'pay_date' => '2026-09-15',
            ...$overrides,
        ]);
});

describe('keeping the tab', function (): void {
    it('adds a charge and shows the balance behind it', function (): void {
        $employee = ($this->hire)();

        ($this->charge)($employee['id'])->assertCreated();
        ($this->charge)($employee['id'], ['amount_cents' => 30_000, 'description' => '2 tins'])
            ->assertCreated();

        $tab = ($this->tab)($employee['id'])->assertOk();

        expect($tab->json('data'))->toHaveCount(2);
        expect($tab->json('meta.balance_cents'))->toBe(80_000);
    });

    it('takes a cash repayment off the balance', function (): void {
        $employee = ($this->hire)();

        ($this->charge)($employee['id'])->assertCreated();
        ($this->charge)($employee['id'], ['kind' => 'payment', 'amount_cents' => 20_000])
            ->assertCreated();

        expect(($this->tab)($employee['id'])->json('meta.balance_cents'))->toBe(30_000);
    });

    it('never reports a negative balance', function (): void {
        $employee = ($this->hire)();

        ($this->charge)($employee['id'], ['amount_cents' => 10_000])->assertCreated();
        ($this->charge)($employee['id'], ['kind' => 'payment', 'amount_cents' => 25_000])
            ->assertCreated();

        expect(($this->tab)($employee['id'])->json('meta.balance_cents'))->toBe(0);
    });

    it('refuses a row that moves nothing', function (): void {
        $employee = ($this->hire)();

        ($this->charge)($employee['id'], ['amount_cents' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount_cents');
    });

    it('lets the storekeeper remove a line they mis-keyed', function (): void {
        $employee = ($this->hire)();
        $row = ($this->charge)($employee['id'])->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/store-credits/{$row['id']}")
            ->assertNoContent();

        expect(($this->tab)($employee['id'])->json('meta.balance_cents'))->toBe(0);
    });
});

describe('what a payslip takes off the tab', function (): void {
    it('takes the whole balance when no cap is set', function (): void {
        $employee = ($this->hire)();
        ($this->charge)($employee['id'], ['amount_cents' => 120_000])->assertCreated();

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['store_deduction_cents'])->toBe(120_000);
        expect($line['deductions_cents'] - 120_000)
            ->toBe($line['sss_cents'] + $line['philhealth_cents'] + $line['pagibig_cents']
                + $line['withholding_tax_cents']);
    });

    it('has no per-person cap: a stray one in the payload is ignored', function (): void {
        $employee = ($this->hire)(['store_deduction_cap_cents' => 50_000]);
        ($this->charge)($employee['id'], ['amount_cents' => 120_000])->assertCreated();

        expect(($this->open)()->json('data.lines.0.store_deduction_cents'))->toBe(120_000);
        expect(($this->tab)($employee['id'])->json('meta'))->not->toHaveKey('cap_cents');
    });

    it('ignores a charge dated after the period it is paying', function (): void {
        $employee = ($this->hire)();

        ($this->charge)($employee['id'], ['charged_on' => '2026-09-18', 'amount_cents' => 90_000])
            ->assertCreated();

        expect(($this->open)()->json('data.lines.0.store_deduction_cents'))->toBe(0);
    });

    it('never takes more than the payslip can bear', function (): void {
        $employee = ($this->hire)([
            'first_name' => 'Nita',
            'pay_basis' => 'monthly',
            'amount_cents' => 800_000,
        ]);

        ($this->charge)($employee['id'], ['amount_cents' => 5_000_000])->assertCreated();

        $line = ($this->open)()->assertCreated()->json('data.lines');
        $hers = collect($line)->firstWhere('employee_id', $employee['id']);

        expect($hers['net_cents'])->toBe(0);
        expect($hers['store_deduction_cents'])->toBeLessThan(5_000_000);
    });
});

describe('settling the tab', function (): void {
    it('writes no repayment while the run is a draft', function (): void {
        $employee = ($this->hire)();
        ($this->charge)($employee['id'], ['amount_cents' => 120_000])->assertCreated();

        ($this->open)()->assertCreated();

        expect(($this->tab)($employee['id'])->json('meta.balance_cents'))->toBe(120_000);
    });

    it('survives the draft being worked out again without double-settling', function (): void {
        $employee = ($this->hire)();
        ($this->charge)($employee['id'], ['amount_cents' => 120_000])->assertCreated();

        $run = ($this->open)()->assertCreated()->json('data');

        $again = $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/rebuild")
            ->assertOk()->json('data');

        expect($again['lines'][0]['store_deduction_cents'])->toBe(120_000);
    });

    it('settles the tab when the run is approved', function (): void {
        $employee = ($this->hire)();
        ($this->charge)($employee['id'], ['amount_cents' => 120_000])->assertCreated();

        $run = ($this->open)()->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/approve")
            ->assertOk();

        $tab = ($this->tab)($employee['id'])->assertOk();

        expect($tab->json('meta.balance_cents'))->toBe(0);
        $payment = collect($tab->json('data'))->firstWhere('kind', 'payment');
        expect($payment['from_payroll'])->toBeTrue();
        expect($payment['amount_cents'])->toBe(120_000);
    });

    it('will not let a payroll repayment be deleted from the tab', function (): void {
        $employee = ($this->hire)();
        ($this->charge)($employee['id'], ['amount_cents' => 120_000])->assertCreated();

        $run = ($this->open)()->assertCreated()->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();

        $payment = StoreCredit::query()->whereNotNull('pay_run_line_id')->firstOrFail();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/store-credits/{$payment->id}")
            ->assertStatus(422);
    });

    it('squares the tab once the payslip that took it is approved', function (): void {
        $employee = ($this->hire)();
        ($this->charge)($employee['id'], ['amount_cents' => 120_000])->assertCreated();

        $run = ($this->open)()->assertCreated()->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();

        expect(($this->tab)($employee['id'])->json('meta.balance_cents'))->toBe(0);
    });
});

describe('leaving somebody out of a contribution', function (): void {
    it('deducts none of the three from a new hire until they are ticked', function (): void {
        $employee = $this->actingAs($this->admin)->postJson('/api/v1/employees', [
            'first_name' => 'Elena',
            'last_name' => 'Bautista',
            'position' => 'Office Staff',
            'contact' => '0917 555 0199',
            'hired_on' => '2026-01-05',
            'amount_cents' => 3_000_000,
        ])->assertCreated()->json('data');

        expect($employee['sss_enrolled'])->toBeFalse()
            ->and($employee['philhealth_enrolled'])->toBeFalse()
            ->and($employee['pagibig_enrolled'])->toBeFalse();

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['sss_cents'])->toBe(0);
        expect($line['philhealth_cents'])->toBe(0);
        expect($line['pagibig_cents'])->toBe(0);
    });

    it('picks up a contribution ticked after the run was opened, on working it out again', function (): void {
        $employee = ($this->hire)(['sss_enrolled' => false]);

        $run = ($this->open)()->assertCreated()->json('data');
        expect($run['lines'][0]['sss_cents'])->toBe(0);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee['id']}", ['sss_enrolled' => true])
            ->assertOk();

        $line = $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/rebuild")
            ->assertOk()
            ->json('data.lines.0');

        expect($line['sss_enrolled'])->toBeTrue()
            ->and($line['sss_cents'])->toBeGreaterThan(0);
    });

    it('deducts all three once they are ticked', function (): void {
        ($this->hire)();

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['sss_cents'])->toBeGreaterThan(0);
        expect($line['philhealth_cents'])->toBeGreaterThan(0);
        expect($line['pagibig_cents'])->toBeGreaterThan(0);
        expect($line['sss_enrolled'])->toBeTrue();
    });

    it('takes nothing for an agency the person is not enrolled with', function (): void {
        ($this->hire)(['sss_enrolled' => false]);

        $line = ($this->open)()->assertCreated()->json('data.lines.0');

        expect($line['sss_cents'])->toBe(0);
        expect($line['philhealth_cents'])->toBeGreaterThan(0);
        expect($line['pagibig_cents'])->toBeGreaterThan(0);
    });

    it('freezes which contributions applied onto the payslip', function (): void {
        $employee = ($this->hire)(['pagibig_enrolled' => false]);

        $line = ($this->open)()->assertCreated()->json('data.lines.0');
        expect($line['pagibig_enrolled'])->toBeFalse();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee['id']}", ['pagibig_enrolled' => true])
            ->assertOk();

        expect($this->actingAs($this->admin)->getJson('/api/v1/payroll')
            ->json('data.0.lines.0.pagibig_enrolled'))->toBeFalse();
    });

    it('has no switch for withholding tax', function (): void {
        $employee = ($this->hire)();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee['id']}", ['withholding_enrolled' => false])
            ->assertOk();

        expect(Employee::findOrFail($employee['id'])->getAttributes())
            ->not->toHaveKey('withholding_enrolled');
    });
});

describe('putting a run with recoveries into the books', function (): void {
    it('posts a paid run that has a store deduction on it', function (): void {
        $employee = ($this->hire)();
        ($this->charge)($employee['id'], ['amount_cents' => 120_000])->assertCreated();

        $run = ($this->open)()->assertCreated()->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();

        $paid = $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/pay")
            ->assertOk();

        expect($paid->json('data.status'))->toBe('paid');
        expect($paid->json('data.journal_entry_id'))->not->toBeNull();
        expect($paid->json('data.journal_reference'))->not->toBeNull();
    });

    it('posts a paid run that has a pay component deduction on it', function (): void {
        ($this->hire)();

        $component = $this->actingAs($this->admin)->postJson('/api/v1/payroll/components', [
            'name' => 'Uniform',
            'kind' => 'deduction',
            'basis' => 'fixed',
            'amount_cents' => 40_000,
            'schedule' => 'each_run',
        ])->assertCreated()->json('data');

        $employee = Employee::query()->firstOrFail();

        $this->actingAs($this->admin)->postJson('/api/v1/payroll/assignments', [
            'employee_id' => $employee->getKey(),
            'pay_component_id' => $component['id'],
            'effective_from' => '2026-01-01',
        ])->assertCreated();

        $run = ($this->open)()->assertCreated()->json('data');
        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/payroll/{$run['id']}/pay")
            ->assertOk();
    });
});
