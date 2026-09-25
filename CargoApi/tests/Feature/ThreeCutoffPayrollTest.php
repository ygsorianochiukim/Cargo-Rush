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

/**
 * A payroll cut off three times a month, and released two days after each.
 *
 * The firm this was built for closes on the **5th, 15th and 25th** and pays on
 * the **7th, 17th and 27th**. The two days are not slack: the office compiles
 * the period's charges and gets the budget released in them, with ten days of
 * billing behind each cutoff.
 *
 * ## What had to change before a third run was safe
 *
 * The limit was two, and the reason was real. Everything that cuts a monthly
 * figure into per-run pieces was written as "halve it":
 *
 *     $isFirstCutoff ? intdiv($rate, 2) : $rate - intdiv($rate, 2)
 *
 * On three runs that pays the first half a salary and each of the other two
 * *another* half — one and a half months of pay, and the same shape again on
 * every statutory contribution. `SecondCutoff` was worse: every run but the
 * first carried the whole month, so two of three did and the firm remitted
 * double.
 *
 * None of it announced itself. Each payslip looked ordinary; only the month
 * added up wrong. `MonthlyShare` is what replaced the halving, and these are
 * the tests that say so.
 *
 * ## What has not changed
 *
 * The withholding table. `config/cargo.php` holds the BIR's **semi-monthly**
 * brackets — 24 periods a year — and a firm on three cutoffs has 36. Running
 * that table on each of them over-states the tax on every payslip. That is a
 * decision for the office rather than a bug to fix quietly, so the screens warn
 * and this file does not pretend otherwise.
 */
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

        // The sentence used to say "twice" and name exactly two days, so a firm
        // on three would have been shown a description of a calendar it is not
        // running.
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

        // Worked out here rather than left to a client to add two days to a
        // date, which is how two screens end up disagreeing about a Sunday.
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

        // Null means "use the install default" and zero means "the same day".
        // Collapsing the two would silently move a firm's pay day by two.
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
        /**
         * The bug this exists to stop.
         *
         * `half` is "first", "second" or "month" — three words, which was
         * enough while a month had at most two periods. On three, the second
         * and third both report "second", and the payroll screen looked a
         * period up by it: clicking 16–25 selected 6–15, and the summary
         * underneath described the wrong fortnight.
         *
         * `index` is the position in the month and is unique by construction,
         * which is why the screen keys on it now.
         */
        $indexes = array_column($this->periods, 'index');

        expect($indexes)->toBe([0, 1, 2])
            ->and(count(array_unique($indexes)))->toBe(3);
    });

    it('is honest that half is not unique, so nothing keys on it again', function (): void {
        // Asserted rather than left as a comment: `half` still means something
        // to a two-period calendar and is still sent, so the duplicate has to
        // be a known and tested fact rather than a surprise.
        expect(array_column($this->periods, 'half'))->toBe(['first', 'second', 'second']);
    });

    it('carries the right dates and release on each one', function (): void {
        // Selecting 6–15 has to give the 6–15 dates and the 17th, not the
        // neighbouring period's. This is the symptom, asserted end to end.
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
        // The 1.5x bug, asserted as the thing it should have been all along.
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
