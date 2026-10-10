<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Support\MonthlyShare;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Shared\Enums\Role;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(NavigationSeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => Role::Administrator->value,
    ]);

    $this->setSchedule = fn (array $payload) => $this->actingAs($this->admin)
        ->patchJson('/api/v1/company', $payload);
});

describe('the schedule', function (): void {
    it('takes the 5th, 15th and 25th', function (): void {
        ($this->setSchedule)(['payroll_cutoff_days' => [5, 15, 25]])->assertOk();

        $calendar = PayrollCalendar::for($this->company->refresh());

        expect($calendar->days)->toBe([5, 15, 25])
            ->and($calendar->runsPerMonth())->toBe(3);
    });

    it('describes itself as three times a month, naming all three days', function (): void {
        ($this->setSchedule)(['payroll_cutoff_days' => [5, 15, 25]])->assertOk();

        expect(PayrollCalendar::for($this->company->refresh())->describe())
            ->toBe('Payroll runs three times a month, cut off on the 5th, the 15th and the 25th.');
    });

    it('releases two days after each cutoff', function (): void {
        ($this->setSchedule)([
            'payroll_cutoff_days' => [5, 15, 25],
            'payroll_release_lag_days' => 2,
        ])->assertOk();

        $calendar = PayrollCalendar::for($this->company->refresh());

        expect($calendar->releaseFor(Carbon::parse('2026-10-05'))->toDateString())->toBe('2026-10-07')
            ->and($calendar->releaseFor(Carbon::parse('2026-10-15'))->toDateString())->toBe('2026-10-17')
            ->and($calendar->releaseFor(Carbon::parse('2026-10-25'))->toDateString())->toBe('2026-10-27');
    });

    it('sends the release day with each period', function (): void {
        ($this->setSchedule)([
            'payroll_cutoff_days' => [5, 15, 25],
            'payroll_release_lag_days' => 2,
        ])->assertOk();

        $periods = $this->actingAs($this->admin)
            ->getJson('/api/v1/company')
            ->assertOk()
            ->json('data.payroll_calendar.example_periods');

        expect($periods)->toHaveCount(3)
            ->and(collect($periods)->pluck('release_on')->every(fn ($day) => $day !== null))->toBeTrue();
    });

    it('still refuses a fourth cutoff', function (): void {
        ($this->setSchedule)(['payroll_cutoff_days' => [5, 10, 20, 31]])->assertStatus(422);
    });

    it('treats a zero lag as paying on the cutoff, not as unset', function (): void {
        ($this->setSchedule)([
            'payroll_cutoff_days' => [5, 15, 25],
            'payroll_release_lag_days' => 0,
        ])->assertOk();

        expect(PayrollCalendar::for($this->company->refresh())->releaseLagDays)->toBe(0);
    });
});

describe('telling the three periods apart', function (): void {
    beforeEach(function (): void {
        ($this->setSchedule)([
            'payroll_cutoff_days' => [5, 15, 25],
            'payroll_release_lag_days' => 2,
        ])->assertOk();

        $this->periods = $this->actingAs($this->admin)
            ->getJson('/api/v1/payroll/periods?month=2026-09')
            ->assertOk()
            ->json('data');
    });

    it('gives every period a position of its own', function (): void {
        $indexes = array_column($this->periods, 'index');

        expect($indexes)->toBe([0, 1, 2])
            ->and(count(array_unique($indexes)))->toBe(3);
    });

    it('is honest that half is not unique, so nothing keys on it again', function (): void {
        expect(array_column($this->periods, 'half'))->toBe(['first', 'second', 'second']);
    });

    it('carries the right dates and release on each one', function (): void {
        expect($this->periods[1])->toMatchArray([
            'index' => 1,
            'start' => '2026-09-06',
            'end' => '2026-09-15',
            'cutoff' => '2026-09-15',
            'release' => '2026-09-17',
        ]);

        expect($this->periods[2])->toMatchArray([
            'index' => 2,
            'start' => '2026-09-16',
            'end' => '2026-09-25',
            'cutoff' => '2026-09-25',
            'release' => '2026-09-27',
        ]);
    });
});

describe('a month, split three ways', function (): void {
    beforeEach(function (): void {
        ($this->setSchedule)([
            'payroll_cutoff_days' => [5, 15, 25],
            'payroll_release_lag_days' => 2,
        ])->assertOk();

        $this->calendar = PayrollCalendar::for($this->company->refresh());
    });

    it('classifies each period as which of three it is', function (): void {
        $periods = $this->calendar->inMonth(2026, 10);

        expect($periods)->toHaveCount(3);

        foreach ($periods as $i => $period) {
            $shape = $this->calendar->classify($period->start, $period->end);

            expect($shape['index'])->toBe($i)
                ->and($shape['count'])->toBe(3);
        }
    });

    it('pays exactly one month of salary across the three runs', function (): void {
        $paid = 0;

        foreach ($this->calendar->inMonth(2026, 10) as $period) {
            $shape = $this->calendar->classify($period->start, $period->end);

            $paid += MonthlyShare::forRun(
                3_000_000,
                $shape['index'],
                $shape['count'],
            );
        }

        expect($paid)->toBe(3_000_000);
    });
});
