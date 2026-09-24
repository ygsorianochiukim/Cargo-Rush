<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * When a firm's pay periods close — its own setting, not the platform's.
 *
 * What these tests are defending:
 *
 *   **The cutoff is per haulier.** It used to be `PAYROLL_RUNS_PER_MONTH`, one
 *   environment variable for every company on the install, which meant a firm
 *   cutting off on the 10th and the 25th could not be answered at all and
 *   moving one firm to a monthly payroll moved all of them. The test that
 *   matters most here is the one with two companies in it: a property about
 *   isolation cannot be shown with one.
 *
 *   **A period may cross a month boundary.** Under `[10, 25]` the first period
 *   of September starts on the 26th of August. Everything that used to assume
 *   a period sat inside one month — matching a range, labelling it, deciding
 *   which payslip it is — has to keep working, and the month a period belongs
 *   to is now the month it *closes* in.
 *
 *   **Nothing changes for a firm that never touches it.** The default is still
 *   the 1st–15th and the 16th–end, down to the wording of the refusal message,
 *   and `PAYROLL_RUNS_PER_MONTH=1` still produces a monthly calendar.
 *
 *   **The setting cannot move under an open draft.** A draft stores the dates
 *   it was opened on; move the cutoffs and it would be paid against a period
 *   that no longer exists, quietly and without an error.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    /** Set this company's cutoff days through the API a settings screen uses. */
    $this->setCutoffs = fn (?array $days) => $this->actingAs($this->admin)
        ->patchJson('/api/v1/company', ['payroll_cutoff_days' => $days]);

    $this->periods = fn (string $month) => $this->actingAs($this->admin)
        ->getJson("/api/v1/payroll/periods?month={$month}");

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

    $this->open = fn (string $start, string $end) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll', [
            'period_start' => $start,
            'period_end' => $end,
            'pay_date' => $end,
        ]);
});

/** An administrator in another company, with that company's roles laid down. */
function cutoffOwnerOf(Company $company): User
{
    app(CompanyProvisioner::class)->provision($company);

    return test()->asCompany($company, fn (): User => User::create([
        'name' => 'Owner of '.$company->name,
        'email' => 'owner@'.$company->code.'.test',
        'password' => 'password',
        'role' => 'administrator',
    ]));
}

describe('the default calendar', function (): void {
    it('still cuts off on the 15th and the end of the month', function (): void {
        $periods = ($this->periods)('2026-09')->assertOk()->json('data');

        expect($periods)->toHaveCount(2)
            ->and($periods[0]['start'])->toBe('2026-09-01')
            ->and($periods[0]['end'])->toBe('2026-09-15')
            ->and($periods[0]['label'])->toBe('1–15 Sep 2026')
            ->and($periods[1]['start'])->toBe('2026-09-16')
            ->and($periods[1]['end'])->toBe('2026-09-30');
    });

    it('reads the end of a short month off the calendar', function (): void {
        // February, where "the 31st" has to mean the 28th — and 2028, where it
        // has to mean the 29th. The cutoff day is stored as 31 either way.
        expect(($this->periods)('2027-02')->assertOk()->json('data.1.end'))->toBe('2027-02-28')
            ->and(($this->periods)('2028-02')->assertOk()->json('data.1.end'))->toBe('2028-02-29');
    });

    it('says a firm that has set nothing is on the install default', function (): void {
        $company = $this->actingAs($this->admin)->getJson('/api/v1/company')->assertOk()->json('data');

        expect($company['payroll_cutoff_days'])->toBeNull()
            ->and($company['payroll_calendar']['cutoff_days'])->toBe([15, 31])
            ->and($company['payroll_calendar']['runs_per_month'])->toBe(2)
            ->and($company['payroll_calendar']['description'])
            ->toBe('Payroll runs twice a month, cut off on the 15th and the last day of the month.');
    });
});

describe('a firm setting its own cutoff', function (): void {
    it('moves both periods, and the first one crosses into the month before', function (): void {
        ($this->setCutoffs)([10, 25])->assertOk();

        $periods = ($this->periods)('2026-09')->assertOk()->json('data');

        expect($periods)->toHaveCount(2)
            // The period that closes on the 10th of September began on the 26th
            // of August. It is September's because that is when it closes, when
            // it is paid and when it is filed.
            ->and($periods[0]['start'])->toBe('2026-08-26')
            ->and($periods[0]['end'])->toBe('2026-09-10')
            ->and($periods[0]['label'])->toBe('26 Aug–10 Sep 2026')
            ->and($periods[0]['half'])->toBe('first')
            ->and($periods[1]['start'])->toBe('2026-09-11')
            ->and($periods[1]['end'])->toBe('2026-09-25')
            ->and($periods[1]['label'])->toBe('11–25 Sep 2026')
            ->and($periods[1]['half'])->toBe('second');
    });

    it('opens a run on a period that crosses a month, and refuses the old one', function (): void {
        ($this->setCutoffs)([10, 25])->assertOk();
        ($this->hire)();

        $run = ($this->open)('2026-08-26', '2026-09-10')->assertCreated()->json('data');

        expect($run['period_label'])->toBe('26 Aug–10 Sep 2026')
            ->and($run['is_first_cutoff'])->toBeTrue()
            ->and($run['staff_count'])->toBe(1);

        // The fortnight this firm used to run is no longer one of its periods,
        // and the refusal says so in the firm's own numbers.
        ($this->open)('2026-09-01', '2026-09-15')
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.period_start.0',
                'Payroll is cut off on the 10th and the 25th here, so a pay period runs '
                .'26 Aug–10 Sep 2026 or 11–25 Sep 2026. Choose one of those.',
            );
    });

    it('splits the salary across the two periods whatever the days are', function (): void {
        ($this->setCutoffs)([10, 25])->assertOk();
        // An odd number of centavos, which is where a careless split loses one.
        ($this->hire)(['amount_cents' => 1_000_001]);

        $first = ($this->open)('2026-08-26', '2026-09-10')->assertCreated()->json('data.lines.0');
        $second = ($this->open)('2026-09-11', '2026-09-25')->assertCreated()->json('data.lines.0');

        expect($first['basic_cents'])->toBe(500_000)
            // The remainder still lands on the second payslip — the rule is
            // about which of the two it is, not about which dates it covers.
            ->and($second['basic_cents'])->toBe(500_001)
            ->and($first['basic_cents'] + $second['basic_cents'])->toBe(1_000_001);
    });

    it('turns a firm over to a monthly payroll on its own', function (): void {
        ($this->setCutoffs)([31])->assertOk();
        ($this->hire)(['amount_cents' => 1_000_001]);

        $periods = ($this->periods)('2026-09')->assertOk()->json('data');

        expect($periods)->toHaveCount(1)
            ->and($periods[0]['start'])->toBe('2026-09-01')
            ->and($periods[0]['end'])->toBe('2026-09-30')
            ->and($periods[0]['half'])->toBe('month');

        // The whole salary on the one payslip, because there is no second one
        // for the rest to land on.
        $line = ($this->open)('2026-09-01', '2026-09-30')->assertCreated()->json('data.lines.0');

        expect($line['basic_cents'])->toBe(1_000_001);
    });

    it('goes back to the install default when the days are cleared', function (): void {
        ($this->setCutoffs)([10, 25])->assertOk();
        ($this->setCutoffs)(null)->assertOk();

        $company = $this->actingAs($this->admin)->getJson('/api/v1/company')->assertOk()->json('data');

        expect($company['payroll_cutoff_days'])->toBeNull()
            ->and($company['payroll_calendar']['cutoff_days'])->toBe([15, 31]);
    });

    it('stores the days ascending however they were sent', function (): void {
        ($this->setCutoffs)([25, 10])->assertOk();

        expect($this->company->refresh()->payroll_cutoff_days)->toBe([10, 25]);
    });
});

/**
 * The property the whole change exists for.
 *
 * One install, two hauliers, two different fortnights. Under the environment
 * variable this was not merely unbuilt — it was unrepresentable.
 */
describe('two companies on one install', function (): void {
    it('gives each firm its own periods', function (): void {
        ($this->setCutoffs)([10, 25])->assertOk();

        $rival = $this->makeCompany('Rival Freight');
        $theirAdmin = cutoffOwnerOf($rival);

        $mine = ($this->periods)('2026-09')->assertOk()->json('data');
        $theirs = $this->actingAs($theirAdmin)
            ->getJson('/api/v1/payroll/periods?month=2026-09')->assertOk()->json('data');

        expect($mine[0]['start'])->toBe('2026-08-26')
            ->and($mine[0]['end'])->toBe('2026-09-10')
            // The neighbour never set anything and is still on the default.
            ->and($theirs[0]['start'])->toBe('2026-09-01')
            ->and($theirs[0]['end'])->toBe('2026-09-15');
    });

    it('lets one firm pay monthly while the other pays twice', function (): void {
        ($this->setCutoffs)([31])->assertOk();

        $rival = $this->makeCompany('Rival Freight');
        $theirAdmin = cutoffOwnerOf($rival);

        expect(($this->periods)('2026-09')->assertOk()->json('data'))->toHaveCount(1)
            ->and($this->actingAs($theirAdmin)
                ->getJson('/api/v1/payroll/periods?month=2026-09')->assertOk()->json('data'))
            ->toHaveCount(2);
    });
});

describe('which period is due', function (): void {
    it('suggests the one that has just closed, on this firm\'s calendar', function (): void {
        ($this->setCutoffs)([10, 25])->assertOk();

        // The 12th of September: the period that closed on the 10th is the one
        // waiting to be paid. On the default calendar it would still be August.
        $this->travelTo(Carbon::create(2026, 9, 12, 9));

        $suggested = collect(($this->periods)('2026-09')->assertOk()->json('data'))
            ->firstWhere('suggested', true);

        expect($suggested['start'])->toBe('2026-08-26')
            ->and($suggested['end'])->toBe('2026-09-10');
    });

    it('falls back to last month when nothing has closed yet this one', function (): void {
        // The 5th of September on the default calendar: the last cutoff to
        // have passed was the end of August.
        $this->travelTo(Carbon::create(2026, 9, 5, 9));

        $data = $this->actingAs($this->admin)->getJson('/api/v1/payroll/periods')->assertOk();

        expect($data->json('meta.month'))->toBe('2026-08')
            ->and(collect($data->json('data'))->firstWhere('suggested', true))
            ->toMatchArray(['start' => '2026-08-16', 'end' => '2026-08-31']);
    });
});

describe('what a firm may set', function (): void {
    it('takes a third cutoff, for a firm that pays three times a month', function (): void {
        // 5th, 15th, 25th — released on the 7th, 17th and 27th. The limit was
        // two while the arithmetic downstream only knew how to halve a month;
        // `MonthlyShare` is what replaced the halving and made a third run safe
        // to pay. See `PayrollCalendar::MAX_CUTOFFS`.
        ($this->setCutoffs)([5, 15, 25])->assertOk();

        expect(PayrollCalendar::for($this->company->refresh())->days)->toBe([5, 15, 25]);
    });

    it('still refuses a fourth, which nothing downstream is built for', function (): void {
        ($this->setCutoffs)([5, 10, 20, 31])
            ->assertStatus(422)
            ->assertJsonPath('errors.payroll_cutoff_days.0',
                'Payroll is cut off up to three times a month, so give one, two or three days — not 4. '
                .'A weekly payroll needs a different tax table and is not set up here.');
    });

    it('refuses two cutoffs on the same day', function (): void {
        ($this->setCutoffs)([15, 15])
            ->assertStatus(422)
            ->assertJsonPath('errors.payroll_cutoff_days.0', 'The cutoff days have to be different days.');
    });

    it('refuses a day that is not a day of the month', function (): void {
        ($this->setCutoffs)([0, 15])->assertStatus(422);
        ($this->setCutoffs)([15, 32])->assertStatus(422);
        ($this->setCutoffs)(['the fifteenth'])->assertStatus(422);
    });

    it('refuses an earlier cutoff past the 27th, because of February', function (): void {
        // The 28th and the 31st both clamp to the 28th in a 28-day February,
        // which would leave the second period starting after it ended.
        ($this->setCutoffs)([28, 31])
            ->assertStatus(422)
            ->assertJsonPath('errors.payroll_cutoff_days.0',
                'The earlier cutoff has to be the 27th or before. Past that it collides with the second one '
                .'in February, which would leave the month with only one pay period.');

        // One day earlier is fine, and February still has two periods.
        ($this->setCutoffs)([27, 31])->assertOk();

        expect(($this->periods)('2027-02')->assertOk()->json('data'))->toHaveCount(2);
    });

    it('allows a late single cutoff, which has nothing to collide with', function (): void {
        ($this->setCutoffs)([28])->assertOk();

        expect(($this->periods)('2027-02')->assertOk()->json('data.0.end'))->toBe('2027-02-28');
    });
});

/**
 * Who may move the cutoff — and it is a narrower set than who runs payroll.
 *
 * The setting lives on the company (`PATCH /company`, `company.manage`), which
 * out of the box only the **administrator** holds: `Role::permissions()` grants
 * it to nobody else, and the accountant who builds, approves and pays every run
 * does not have it. That is a deliberate split rather than an oversight — the
 * cutoff decides the shape of every future period, and the person running this
 * fortnight's payroll is not thereby deciding what a fortnight is here.
 *
 * Pinned with a test because it is the kind of boundary that erodes silently:
 * the endpoint is shared with the contact details and the map pin, and somebody
 * widening the permission for one of those would widen it for this.
 */
describe('who may change it', function (): void {
    it('lets the administrator set it', function (): void {
        ($this->setCutoffs)([10, 25])->assertOk();
    });

    it('refuses the accountant, who runs payroll but does not define it', function (): void {
        $accountant = User::where('email', 'accounts@cargorush.ph')->firstOrFail();

        // They hold `payroll.manage` — they can build, approve and pay a run.
        $this->actingAs($accountant)->getJson('/api/v1/payroll')->assertOk();

        // And still cannot move the cutoff, or read the company record it
        // sits on. No 403-on-click either: the screen is behind `access.view`,
        // which they also do not hold, so it is not offered in the first place.
        $this->actingAs($accountant)
            ->patchJson('/api/v1/company', ['payroll_cutoff_days' => [10, 25]])
            ->assertForbidden();

        $this->actingAs($accountant)->getJson('/api/v1/company')->assertForbidden();
    });

    it('refuses a driver outright', function (): void {
        $driver = User::where('email', 'marco@cargorush.ph')->firstOrFail();

        $this->actingAs($driver)
            ->patchJson('/api/v1/company', ['payroll_cutoff_days' => [10, 25]])
            ->assertForbidden();
    });
});

describe('moving the cutoff under a run', function (): void {
    it('refuses while a draft is open, and names it', function (): void {
        ($this->hire)();
        $run = ($this->open)('2026-09-01', '2026-09-15')->assertCreated()->json('data');

        ($this->setCutoffs)([10, 25])
            ->assertStatus(422)
            ->assertJsonPath('errors.payroll_cutoff_days.0', sprintf(
                '%s is still a draft for 1–15 Sep 2026. Approve it or delete it before moving the cutoff — '
                .'otherwise it would be paid on a period that no longer exists.',
                $run['reference'],
            ));
    });

    it('allows it once the draft is approved, and leaves that run alone', function (): void {
        ($this->hire)();
        $run = ($this->open)('2026-09-01', '2026-09-15')->assertCreated()->json('data');

        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$run['id']}/approve")->assertOk();

        // Approved figures are frozen on the lines, so the calendar underneath
        // them is free to move — that is what freezing a payslip is for.
        ($this->setCutoffs)([10, 25])->assertOk();

        $after = $this->actingAs($this->admin)
            ->getJson("/api/v1/payroll/{$run['id']}")->assertOk()->json('data');

        // The run still says what it said: its own dates, its own label, and
        // still the first payslip of its month.
        expect($after['period_start'])->toBe('2026-09-01')
            ->and($after['period_end'])->toBe('2026-09-15')
            ->and($after['period_label'])->toBe('1–15 Sep 2026')
            ->and($after['is_first_cutoff'])->toBeTrue();
    });
});

/**
 * The calendar on its own, without the HTTP layer.
 *
 * The arithmetic that everything above rests on, asserted directly where a
 * failure names the rule rather than a route.
 */
describe('the calendar itself', function (): void {
    it('drops anything unusable rather than throwing on a bad row', function (): void {
        // What `of()` is for: it reads a column back, and a payslip is the
        // wrong place to discover somebody wrote something odd by hand.
        // Three is a real calendar now, so a list of three comes back whole —
        // sorted, which is the other half of what `of()` repairs.
        expect(PayrollCalendar::of([15, 31, 20])->days)->toBe([15, 20, 31])
            // Past three is still dropped: nothing downstream is built for it.
            ->and(PayrollCalendar::of([5, 10, 20, 31])->days)->toBe([5, 10, 20])
            ->and(PayrollCalendar::of([0, 99, 'x'])->days)->toBe([15, 31])
            ->and(PayrollCalendar::of([])->days)->toBe([15, 31]);
    });

    it('classifies a run whose dates are not a period any more', function (): void {
        $calendar = PayrollCalendar::of([10, 25]);

        // A run opened under the old 1st/16th calendar. Not refused — it
        // already exists and has been paid — and answered honestly.
        // `index` and `count` ride along with the two booleans, because that is
        // what the money needs: a monthly salary is cut into `count` pieces and
        // "the second of three" cannot be said with a pair of flags. See
        // `MonthlyShare`.
        expect($calendar->classify(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-15')))
            ->toBe(['first' => true, 'only' => false, 'index' => 0, 'count' => 2])
            ->and($calendar->classify(Carbon::parse('2026-09-16'), Carbon::parse('2026-09-30')))
            ->toBe(['first' => false, 'only' => false, 'index' => 1, 'count' => 2]);
    });

    it('names the days the way an office would say them', function (): void {
        expect(PayrollCalendar::dayLabel(1))->toBe('the 1st')
            ->and(PayrollCalendar::dayLabel(2))->toBe('the 2nd')
            ->and(PayrollCalendar::dayLabel(3))->toBe('the 3rd')
            ->and(PayrollCalendar::dayLabel(11))->toBe('the 11th')
            ->and(PayrollCalendar::dayLabel(15))->toBe('the 15th')
            ->and(PayrollCalendar::dayLabel(22))->toBe('the 22nd')
            // Not "the 31st": September has thirty days, and this is the one
            // number on a settings screen that would otherwise be wrong.
            ->and(PayrollCalendar::dayLabel(31))->toBe('the last day of the month');
    });

    it('labels a period that crosses a new year with both years', function (): void {
        $calendar = PayrollCalendar::of([10, 25]);

        $january = $calendar->inMonth(2027, 1);

        expect($january[0]->label())->toBe('26 Dec 2026–10 Jan 2027');
    });
});
