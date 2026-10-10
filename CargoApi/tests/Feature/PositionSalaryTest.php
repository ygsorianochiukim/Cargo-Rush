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
        $position = ($this->addPosition)([
            'name' => 'Dispatcher',
            'trainee_amount_cents' => 0,
            'probationary_amount_cents' => 0,
            'regular_amount_cents' => 0,
        ])->assertCreated()->json('data');

        expect($position['has_rate_card'])->toBeFalse();
    });

    it('prices a per-trip job by the haul', function (): void {
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
            ->and($employee['contract_effective_from'])->toBe('2026-01-05')
            ->and($employee['position'])->toBe('Yard Marshal');
    });

    it('puts a hire with nothing stated on the probationary figure', function (): void {
        $position = ($this->addPosition)()->assertCreated()->json('data');

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

        $employee = ($this->hire)([
            'position_id' => $position['id'],
            'amount_cents' => 3_000_000,
        ])->assertCreated()->json('data');

        expect($employee['amount_cents'])->toBe(3_000_000);
    });

    it('takes the basis from the job when only a figure is typed', function (): void {
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

describe('after the rate changes', function (): void {
    it('leaves everybody already hired exactly where they were', function (): void {
        $position = ($this->addPosition)()->assertCreated()->json('data');
        $employee = ($this->hire)(['position_id' => $position['id']])
            ->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/access/positions/{$position['id']}", [
                'regular_amount_cents' => 3_600_000,
            ])->assertOk();

        $after = $this->actingAs($this->admin)
            ->getJson("/api/v1/employees/{$employee['id']}")->assertOk()->json('data');

        expect($after['amount_cents'])->toBe(2_400_000);

        $next = ($this->hire)([
            'first_name' => 'Jun', 'last_name' => 'Santos',
            'position_id' => $position['id'],
        ])->assertCreated()->json('data');

        expect($next['amount_cents'])->toBe(3_600_000);
    });
});

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

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee['id']}", [
                'amount_cents' => 3_300_000,
                'effective_from' => '2027-06-01',
            ])->assertOk();

        $after = $this->actingAs($this->admin)
            ->getJson("/api/v1/employees/{$employee['id']}")->assertOk()->json('data');

        expect($after['amount_cents'])->toBe(2_400_000);

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

        $edited = $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee['id']}", ['contact' => '0917 555 0200'])
            ->assertOk()->json('data');

        expect($edited['amount_cents'])->toBe(3_000_000)
            ->and(Contract::query()->where('employee_id', $employee['id'])->count())->toBe(1);
    });
});

describe('two companies', function (): void {
    it('lets each price the same job differently, and hides one from the other', function (): void {
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

        expect($theirs->regular_amount_cents)->toBe(1_800_000);

        $mine = collect($this->actingAs($this->admin)
            ->getJson('/api/v1/access/positions')->assertOk()->json('data'));

        expect($mine->firstWhere('id', $mineRow['id'])['regular_amount_cents'])->toBe(2_400_000)
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

        $seen = $this->asCompany($rival, fn (): int => Contract::query()->count());

        expect($seen)->toBe(0)
            ->and(Contract::query()->count())->toBe(1);
    });
});

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
