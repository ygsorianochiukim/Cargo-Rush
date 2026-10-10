<?php

declare(strict_types=1);

use App\Domain\Hr\Models\Contract;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\User;
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

    $this->position = Position::create([
        'key' => 'yard-marshal',
        'name' => 'Yard Marshal',
        'pay_basis' => 'monthly',
        'trainee_amount_cents' => 1_800_000,
        'probationary_amount_cents' => 2_100_000,
        'regular_amount_cents' => 2_400_000,
    ]);

    $this->hire = fn (array $overrides = []) => Employee::findOrFail(
        $this->actingAs($this->admin)->postJson('/api/v1/employees', [
            'first_name' => 'Elena',
            'last_name' => 'Bautista',
            'position_id' => $this->position->id,
            'employment_type' => 'regular',
            'contact' => '0917 555 0199',
            'hired_on' => '2026-01-05',
            ...$overrides,
        ])->assertCreated()->json('data.id'),
    );

    $this->raise = fn (Employee $employee, array $body) => $this->actingAs($this->admin)
        ->postJson("/api/v1/employees/{$employee->id}/contracts", $body);
});

describe('the history', function (): void {
    it('starts with the contract the hire opened', function (): void {
        $employee = ($this->hire)();

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/employees/{$employee->id}/contracts")->assertOk();

        $rows = $response->json('data');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['amount_cents'])->toBe(2_400_000)
            ->and($rows[0]['pay_basis'])->toBe('monthly')
            ->and($rows[0]['tier'])->toBe('regular')
            ->and($rows[0]['effective_from'])->toBe('2026-01-05')
            ->and($rows[0]['summary'])->toBe('₱24,000 a month')
            ->and($rows[0]['reason'])->toContain('Yard Marshal')
            ->and($response->json('meta.current_contract_id'))->toBe($rows[0]['id']);
    });

    it('lists them newest first', function (): void {
        $employee = ($this->hire)();

        ($this->raise)($employee, ['amount_cents' => 2_700_000, 'effective_from' => '2026-06-01'])
            ->assertCreated();

        $rows = $this->actingAs($this->admin)
            ->getJson("/api/v1/employees/{$employee->id}/contracts")->assertOk()->json('data');

        expect($rows)->toHaveCount(2)
            ->and($rows[0]['effective_from'])->toBe('2026-06-01')
            ->and($rows[1]['effective_from'])->toBe('2026-01-05');
    });
});

describe('a rise', function (): void {
    it('appends rather than overwriting', function (): void {
        $employee = ($this->hire)();
        $opening = $employee->contractOn();

        ($this->raise)($employee, [
            'amount_cents' => 2_700_000,
            'effective_from' => '2026-06-01',
            'reason' => 'Annual increase',
        ])->assertCreated()
            ->assertJsonPath('data.amount_cents', 2_700_000)
            ->assertJsonPath('data.reason', 'Annual increase');

        expect($opening->refresh()->amount_cents)->toBe(2_400_000)
            ->and($opening->effective_from->toDateString())->toBe('2026-01-05')
            ->and($employee->refresh()->amountCentsOn())->toBe(2_700_000);
    });

    it('carries the basis and the tier over when only the figure moves', function (): void {
        $employee = ($this->hire)();

        $row = ($this->raise)($employee, ['amount_cents' => 2_700_000])
            ->assertCreated()->json('data');

        expect($row['pay_basis'])->toBe('monthly')
            ->and($row['tier'])->toBe('regular');
    });

    it('records who agreed it', function (): void {
        $employee = ($this->hire)();

        ($this->raise)($employee, ['amount_cents' => 2_700_000])->assertCreated();

        $rows = $this->actingAs($this->admin)
            ->getJson("/api/v1/employees/{$employee->id}/contracts")->assertOk()->json('data');

        expect($rows[0]['author'])->toBe($this->admin->name);
    });

    it('does not pay until the day it starts', function (): void {
        $employee = ($this->hire)();

        $row = ($this->raise)($employee, [
            'amount_cents' => 3_300_000,
            'effective_from' => '2027-06-01',
        ])->assertCreated()->json('data');

        expect($row['has_started'])->toBeFalse()
            ->and($employee->refresh()->amountCentsOn())->toBe(2_400_000);

        $meta = $this->actingAs($this->admin)
            ->getJson("/api/v1/employees/{$employee->id}/contracts")->assertOk()->json('meta');

        expect($meta['amount_cents'])->toBe(2_400_000)
            ->and($meta['current_contract_id'])->not->toBe($row['id']);
    });

    it('answers a question about a date in the past with the figure of the day', function (): void {
        $employee = ($this->hire)();

        ($this->raise)($employee, ['amount_cents' => 2_700_000, 'effective_from' => '2026-06-01'])
            ->assertCreated();

        expect($employee->refresh()->amountCentsOn('2026-03-15'))->toBe(2_400_000)
            ->and($employee->amountCentsOn('2026-06-01'))->toBe(2_700_000)
            ->and($employee->amountCentsOn('2026-09-30'))->toBe(2_700_000);
    });

    it('lets a correction on the same day win', function (): void {
        $employee = ($this->hire)();

        ($this->raise)($employee, ['amount_cents' => 270_000, 'effective_from' => '2026-06-01'])
            ->assertCreated();
        ($this->raise)($employee, ['amount_cents' => 2_700_000, 'effective_from' => '2026-06-01'])
            ->assertCreated();

        expect($employee->refresh()->amountCentsOn('2026-06-02'))->toBe(2_700_000);
    });

    it('moves somebody onto a different basis when the caller says so', function (): void {
        $employee = ($this->hire)();

        $row = ($this->raise)($employee, [
            'amount_cents' => 150_000,
            'pay_basis' => 'per_trip',
            'reason' => 'Moved onto the trucks',
        ])->assertCreated()->json('data');

        expect($row['pay_basis'])->toBe('per_trip')
            ->and($row['pay_basis_unit'])->toBe('a trip')
            ->and($row['summary'])->toBe('₱1,500 a trip');
    });
});

describe('raising one person', function (): void {
    it('leaves the job and everybody else on it exactly where they were', function (): void {
        $hers = ($this->hire)();
        $his = ($this->hire)([
            'first_name' => 'Jun', 'last_name' => 'Santos', 'contact' => '0917 555 0200',
        ]);

        ($this->raise)($hers, ['amount_cents' => 2_900_000])->assertCreated();

        expect($his->refresh()->amountCentsOn())->toBe(2_400_000)
            ->and($this->position->refresh()->regular_amount_cents)->toBe(2_400_000)
            ->and($hers->refresh()->amountCentsOn())->toBe(2_900_000);
    });

    it('survives the job being repriced afterwards', function (): void {
        $employee = ($this->hire)();

        ($this->raise)($employee, ['amount_cents' => 2_900_000])->assertCreated();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/access/positions/{$this->position->id}", [
                'regular_amount_cents' => 2_500_000,
            ])->assertOk();

        expect($employee->refresh()->amountCentsOn())->toBe(2_900_000);
    });
});

describe('taking back a row typed by mistake', function (): void {
    it('removes it and puts the previous figure back in force', function (): void {
        $employee = ($this->hire)();

        $wrong = ($this->raise)($employee, ['amount_cents' => 29_000_000])
            ->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/employees/{$employee->id}/contracts/{$wrong['id']}")
            ->assertNoContent();

        expect($employee->refresh()->amountCentsOn())->toBe(2_400_000)
            ->and(Contract::withTrashed()->find($wrong['id'])->trashed())->toBeTrue();
    });

    it('will not take back a row belonging to somebody else', function (): void {
        $hers = ($this->hire)();
        $his = ($this->hire)([
            'first_name' => 'Jun', 'last_name' => 'Santos', 'contact' => '0917 555 0200',
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/employees/{$his->id}/contracts/{$hers->contractOn()->id}")
            ->assertNotFound();

        expect($hers->refresh()->contractOn())->not->toBeNull();
    });
});

describe('who may look', function (): void {
    it('lets an HR officer read what people are on but not change it', function (): void {
        $employee = ($this->hire)();

        $hr = User::factory()->create(['role' => 'hr-officer']);

        $this->actingAs($hr)
            ->getJson("/api/v1/employees/{$employee->id}/contracts")
            ->assertOk();

        $this->actingAs($hr)
            ->postJson("/api/v1/employees/{$employee->id}/contracts", ['amount_cents' => 9_000_000])
            ->assertForbidden();

        expect($employee->refresh()->amountCentsOn())->toBe(2_400_000);
    });

    it('keeps it away from somebody with no payroll access at all', function (): void {
        $employee = ($this->hire)();

        $dispatcher = User::factory()->create(['role' => 'dispatcher']);

        $this->actingAs($dispatcher)
            ->getJson("/api/v1/employees/{$employee->id}/contracts")
            ->assertForbidden();
    });

    it('lets somebody who holds both the roster and the payroll write one', function (): void {
        $employee = ($this->hire)();

        $manager = User::factory()->create(['role' => 'general-manager']);

        $this->actingAs($manager)
            ->postJson("/api/v1/employees/{$employee->id}/contracts", ['amount_cents' => 2_900_000])
            ->assertCreated();

        expect($employee->refresh()->amountCentsOn())->toBe(2_900_000);
    });
});
