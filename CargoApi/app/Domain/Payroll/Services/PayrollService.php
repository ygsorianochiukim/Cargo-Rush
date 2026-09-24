<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Accounting\DTO\JournalEntryData;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Payroll\Models\PayComponent;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Models\PayRunLineComponent;
use App\Domain\Payroll\Support\MonthlyShare;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Shared\Enums\DeductionSchedule;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\PayComponentKind;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payroll: building a run, freezing it, paying it, and putting it in the books.
 *
 * ## The four verbs, and why they are verbs
 *
 * **Build** takes a period and works out what everybody is owed. It can be run
 * again — that is what a draft is for, and the second run replaces the first
 * rather than adding to it.
 *
 * **Approve** freezes the figures. Somebody has looked at them and said yes,
 * and payslips can go out — which means nothing may move afterwards. Same
 * argument as posting a journal entry.
 *
 * **Pay** records that the money went out and **posts the run to the ledger**:
 * salaries to expense, each agency's share to its own payable, the net to cash.
 * This is what makes payroll part of the accounts rather than a spreadsheet
 * beside them, and it is the first thing in this system that posts itself.
 *
 * **Delete** is for a draft only. An approved run has been shown to people.
 *
 * ## Who is on a run, and how each of them is worked out
 *
 * Active employees, on one of three **pay bases** — see `PayBasis`.
 *
 * **Monthly** staff are on every run: a salary agreed once, split across the
 * month's cutoffs. This is what payroll has always done.
 *
 * **Daily** and **per-trip** staff are on a run whenever they have a `drivers`
 * record to find their work under. Each cutoff sums what the daily truck sheet
 * recorded them earning between the period's two dates, and that is their pay —
 * which is how trip money reaches a payslip, a government contribution and the
 * books at all.
 *
 * Their figures come from the sheet rather than from a rate card, because a
 * fleet's per-trip arrangements are endless and a rate card here would be wrong
 * within a month. See `TripPayService`.
 *
 * A firm that hands drivers their trip money in cash against that same sheet
 * should leave those people on a **monthly** basis with no salary — which keeps
 * them off a run, as it always did — rather than paying it twice.
 *
 * The office can still add anything a person is owed as an adjustment on their
 * line, whatever basis they are on.
 */
class PayrollService
{
    public function __construct(
        private readonly StatutoryDeductions $deductions,
        private readonly PayComponentService $components,
        private readonly TripPayService $tripPay,
        private readonly StoreCreditService $storeCredit,
        private readonly JournalService $journal,
        private readonly NotificationService $notifications,
        private readonly Tenant $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return PayRun::query()
            ->with(['lines.components', 'approvedBy:id,name', 'journalEntry:id,reference'])
            ->when(
                ! empty($filters['status']),
                fn ($query) => $query->whereIn('status', (array) $filters['status']),
            )
            ->when(
                ! empty($filters['from']),
                fn ($query) => $query->whereDate('period_start', '>=', $filters['from']),
            )
            ->when(
                ! empty($filters['to']),
                fn ($query) => $query->whereDate('period_end', '<=', $filters['to']),
            )
            ->inPayrollOrder()
            ->paginate($perPage);
    }

    public function find(string $id): PayRun
    {
        return PayRun::query()
            ->with(['lines.components', 'lines.employee:id,employee_no,first_name,last_name', 'approvedBy:id,name', 'journalEntry:id,reference'])
            ->findOrFail($id);
    }

    /**
     * Open a run for a period and work out everybody's pay.
     *
     * One transaction over the run and its lines, because a run with no lines
     * is not a payroll — and re-running a draft replaces the lines wholesale
     * for the same reason an edited journal entry replaces its own: merging
     * would leave somebody paid from two different calculations.
     */
    public function build(
        Carbon $periodStart,
        Carbon $periodEnd,
        Carbon $payDate,
        ?User $author = null,
        ?PayRun $existing = null,
    ): PayRun {
        if ($existing !== null) {
            $this->mustBeOpen($existing);
        }

        return DB::transaction(function () use ($periodStart, $periodEnd, $payDate, $author, $existing): PayRun {
            $run = $existing ?? new PayRun;

            $run->fill([
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'pay_date' => $payDate->toDateString(),
                'status' => PayRun::DRAFT,
            ]);

            if ($author !== null && $run->created_by === null) {
                $run->created_by = $author->id;
            }

            $run->save();

            /**
             * What the office typed onto these payslips, read before the lines
             * go. Folded back into the resolved list below, so a carried-over
             * deduction goes through the same arithmetic as an assigned one.
             */
            $byHand = $this->handAddedComponents($run);

            // Wholesale, not merged. See the note above.
            $run->lines()->delete();

            // Which cutoff this is, worked out once for the whole run rather
            // than per employee: it is a fact about the period, and every
            // payslip on the run shares it.
            $cutoff = $this->calendar()->classify($run->period_start, $run->period_end);
            $schedule = $this->schedule();

            /**
             * Everything the run needs from the database, fetched once.
             *
             * Both of these used to be asked per employee, which made building
             * a run cost a fixed number of round trips **times the headcount**
             * — around five each, so a 90-strong roster was some four hundred
             * queries for a job that reads two tables. A draft is rebuilt every
             * time somebody corrects a figure, so that cost was paid over and
             * over.
             *
             * The order matters: the sheet is read first because a per-trip
             * driver's monthly basic is inferred from what they earned, and the
             * components need that figure to work a percentage out of.
             */
            $payable = $this->payable($run->period_end);
            $sheet = $this->tripPay->forEmployees($payable, $run->period_start, $run->period_end);

            $earnings = [];
            $monthlyBasics = [];

            foreach ($payable as $employee) {
                $earned = $this->earningsFor(
                    $employee,
                    $cutoff,
                    $sheet[$employee->getKey()] ?? null,
                    $run->period_end,
                );

                $earnings[$employee->getKey()] = $earned;
                $monthlyBasics[$employee->getKey()] = $earned['monthly_equivalent_cents'];
            }

            $components = $this->components->resolveFor(
                $payable,
                $monthlyBasics,
                $run->period_start,
                $run->period_end,
                $cutoff,
            );

            /**
             * The store tab, as at the **end of the period** and not as at
             * today.
             *
             * That is what makes a draft rebuildable. A run for the first half
             * of the month, worked out again on the 20th, must not suddenly
             * deduct the rice somebody took on the 18th: that charge belongs to
             * the next payslip, and a rebuild that moved it would change a
             * figure the office had already checked.
             *
             * One query for the whole roster, like the sheet and the
             * components above.
             */
            $tabs = $this->storeCredit->balances(
                $payable->modelKeys(),
                $run->period_end->toDateString(),
            );

            foreach ($payable as $employee) {
                $this->writeLine(
                    $run,
                    $employee,
                    $cutoff,
                    $schedule,
                    $earnings[$employee->getKey()],
                    [
                        ...($components[$employee->getKey()] ?? []),
                        ...($byHand[$employee->getKey()] ?? []),
                    ],
                    $tabs[$employee->getKey()] ?? 0,
                );
            }

            return $run->refresh()->load('lines.components');
        });
    }

    /**
     * Everybody the run pays.
     *
     * **The pay basis on the person decides, on its own.**
     *
     * Monthly staff with a salary on record, and trip- or day-paid staff with a
     * `drivers` record to find their work under. Nothing else votes.
     *
     * There used to be a company switch that could veto the second group, and
     * removing it was the point: setting somebody to *per trip* and then
     * finding them on no payslip — because of a setting on another screen in
     * another module — is the kind of surprise that makes a payroll module feel
     * untrustworthy. A firm that settles its drivers in cash against the truck
     * sheet leaves them on a monthly basis with no salary, which keeps them off
     * a run exactly as it always did.
     *
     * Ordered by surname so a register reads like a register.
     *
     * @return Collection<int, Employee>
     */
    public function payable(Carbon|string|null $on = null)
    {
        return Employee::query()
            ->where('status', StatusValue::Active->value)
            // The contract in force, read for the whole roster in one query
            // rather than one per person as each line is worked out.
            ->with(['contracts' => fn ($contracts) => $contracts->inForceOn($on)])
            ->orderBy('last_name')
            ->get()
            ->filter(function (Employee $employee) use ($on): bool {
                $contract = $employee->contractOn($on);

                // Nobody has said what this person is paid. Not a zero payslip
                // — no payslip, until the office writes one.
                if ($contract === null) {
                    return false;
                }

                $basis = $contract->pay_basis ?? PayBasis::Monthly;

                // A monthly contract at zero is somebody half set up, and a
                // ₱0.00 payslip is worse than none.
                if (! $basis->countsWork()) {
                    return (int) $contract->amount_cents > 0;
                }

                // A trip- or day-paid person with no `drivers` record has no
                // work this could find — every sheet row and every trip names a
                // driver — so they are left off rather than given an empty
                // payslip.
                return $employee->driver_id !== null;
            })
            ->values();
    }

    /**
     * One person's line, with the figures frozen onto it.
     *
     * The name and the position are copied along with the money — a payslip is
     * a statement about a fortnight and has to keep saying what it said after
     * somebody is promoted. See `PayRunLine`.
     *
     * @param  array{first: bool, only: bool, index: int, count: int}  $cutoff
     */
    private function writeLine(
        PayRun $run,
        Employee $employee,
        array $cutoff,
        DeductionSchedule $schedule,
        array $earned,
        array $components,
        int $storeBalanceCents = 0,
    ): PayRunLine {
        $monthly = $earned['monthly_equivalent_cents'];

        /**
         * The earnings and the components arrive worked out, from `build()`.
         *
         * Both are fetched for the whole run in one query each rather than per
         * person — see the note there. The components were resolved *before*
         * the statutory figures below because a **taxable** earning belongs in
         * the tax base, and the BIR's order is contributions first, then tax on
         * what is left.
         *
         * A percentage component reads the monthly equivalent, so 10% of the
         * basic means 10% of what a per-trip driver actually earns rather than
         * 10% of a salary they do not have.
         */
        $componentEarnings = 0;
        $componentDeductions = 0;
        $taxableEarnings = 0;

        foreach ($components as $component) {
            if ($component['kind'] === PayComponentKind::Earning->value) {
                $componentEarnings += $component['amount_cents'];

                if ($component['taxable']) {
                    $taxableEarnings += $component['amount_cents'];
                }

                continue;
            }

            $componentDeductions += $component['amount_cents'];
        }

        // Worked out by basis — a salary is split across the month's cutoffs,
        // while trip and daily pay are already this period's. See `earningsFor`.
        $basic = $earned['basic_cents'];

        /**
         * The tax base is the period's basic **plus any taxable earning**.
         *
         * The contributions are not: SSS, PhilHealth and Pag-IBIG are computed
         * from the monthly *basic*, which is what the agencies' own schedules
         * read, and an allowance does not move them. Tax is different — a
         * taxable allowance is taxable pay, and leaving it out would understate
         * the withholding on every payslip that carried one.
         *
         * Non-taxable is the default on a component, and most of what a fleet
         * pays on top of a basic genuinely is: rice, uniform and medical
         * allowances are de minimis benefits up to the BIR's ceilings. So a
         * firm that has not thought about this gets exactly the arithmetic
         * payroll did before components existed.
         */
        $enrolled = $employee->statutoryEnrolment();

        $statutory = $this->deductions->for(
            $monthly,
            $basic + $taxableEarnings,
            $cutoff['index'],
            $cutoff['count'],
            $schedule,
            $enrolled,
        );

        /**
         * The store tab, taken last — after everything else is known.
         *
         * Last because it is the only deduction that has to look at what is
         * left. A tab is a recovery, and a recovery that takes more than the
         * payslip holds has stopped being a recovery and become an unpayable
         * wage: the person is handed nothing and still owes money. So it is
         * floored at whatever the payslip can actually bear, and the rest
         * stays on the tab for the next one.
         *
         * Which means a tab settles itself over as many payslips as it takes,
         * with no schedule for anybody to maintain — the behaviour a
         * `pay_components` deduction cannot express, and the reason this is not
         * one.
         */
        $beforeStore = $basic + $componentEarnings
            - $statutory['sss'] - $statutory['philhealth'] - $statutory['pagibig']
            - $statutory['withholding_tax'] - $componentDeductions;

        $store = min(
            $this->storeCredit->deductionFor($employee, $storeBalanceCents),
            max(0, $beforeStore),
        );

        $line = new PayRunLine([
            'pay_run_id' => $run->getKey(),
            'employee_id' => $employee->getKey(),
            'employee_no' => $employee->employee_no,
            'name' => trim($employee->first_name.' '.$employee->last_name),
            'position' => $employee->position,
            // How this payslip was worked out, and the workings behind it —
            // frozen, because "₱12,400" with no indication of what it was
            // 12,400 *of* is a figure nobody holding it can check.
            'pay_basis' => $earned['basis']->value,
            'basic_cents' => $basic,
            'days_worked' => $earned['days_worked'],
            'sheet_days' => $earned['sheet_days'],
            'trips' => $earned['trips'],
            'allowance_cents' => 0,
            'overtime_cents' => 0,
            'adjustments_cents' => 0,
            // The structure's totals, kept apart from the hand-typed figures
            // above so a rebuild cannot wipe somebody's correction. See the
            // migration that adds these two columns.
            'component_earnings_cents' => $componentEarnings,
            'component_deductions_cents' => $componentDeductions,
            'sss_cents' => $statutory['sss'],
            'philhealth_cents' => $statutory['philhealth'],
            'pagibig_cents' => $statutory['pagibig'],
            'withholding_tax_cents' => $statutory['withholding_tax'],
            // Frozen beside the figures: "SSS ₱0.00" has two quite different
            // explanations, and only one of them is for the office to fix.
            'sss_enrolled' => $enrolled['sss'],
            'philhealth_enrolled' => $enrolled['philhealth'],
            'pagibig_enrolled' => $enrolled['pagibig'],
            'store_deduction_cents' => $store,
            'other_deductions_cents' => 0,
        ]);

        // Totals first, then one insert — rather than inserting and updating
        // the row we just wrote.
        $this->totalsOn($line)->save();

        /**
         * Itemised, and frozen. The totals above are what the payslip adds up;
         * these are what it says — and they have to keep saying it after the
         * catalogue is edited. See `PayRunLineComponent`.
         *
         * One insert each rather than a bulk write, deliberately: a bulk
         * `insert()` skips model events, and `BelongsToCompany` stamps
         * `company_id` in one. A handful of rows per payslip is a fair price
         * for not having a tenancy stamp that depends on remembering.
         */
        foreach ($components as $component) {
            PayRunLineComponent::create([
                'pay_run_line_id' => $line->getKey(),
                'pay_component_id' => $component['pay_component_id']
                    ?? $component['component']?->getKey(),
                'name' => $component['name'],
                'kind' => $component['kind'],
                'taxable' => $component['taxable'],
                'amount_cents' => $component['amount_cents'],
                'added_by_hand' => $component['added_by_hand'] ?? false,
            ]);
        }

        return $line;
    }

    /**
     * What this person earned in this period, and what it is a month's worth
     * of.
     *
     * Three bases, and the difference between them is not the arithmetic but
     * **what the period means**.
     *
     * A **monthly** salary is a figure for a month, so the payslip carries a
     * share of it: half on each cutoff, with the remainder on the second so the
     * two add up to the month exactly. Halving with `intdiv` on both runs would
     * quietly short a salary ending in an odd centavo by one centavo every
     * month — twelve a year per employee, permanently, and impossible to find
     * from either payslip.
     *
     * **Daily** and **per-trip** pay is already this period's. It is summed
     * from the days actually worked between these two dates, so there is
     * nothing to split — splitting it would halve a fortnight's work.
     *
     * ## The monthly equivalent, and why it is an estimate
     *
     * SSS, PhilHealth and Pag-IBIG are computed from a **monthly** figure, and
     * a per-trip driver has no monthly figure. So one is inferred: this
     * period's earnings, multiplied by the number of periods in the month.
     *
     * That is an approximation and it moves with the work — a driver who had a
     * quiet fortnight contributes less that fortnight. Which is closer to right
     * than the alternatives: a fixed guess would over-deduct in a lean month
     * and under-remit in a busy one, and zero would leave somebody with no
     * contributions at all, which is the state this feature exists to end.
     * It sits alongside the module's other honest approximation — the SSS
     * percentage-with-a-ceiling — and like that one, every figure it produces
     * is editable on the line by an office that knows better.
     *
     * @param  array{first: bool, only: bool, index: int, count: int}  $cutoff
     * @return array{basis: PayBasis, basic_cents: int, monthly_equivalent_cents: int, days_worked: int, sheet_days: int, trips: int}
     */
    private function earningsFor(Employee $employee, array $cutoff, ?array $sheet = null, Carbon|string|null $on = null): array
    {
        $contract = $employee->contractOn($on);
        $basis = $contract?->pay_basis ?? PayBasis::Monthly;
        $rate = (int) ($contract?->amount_cents ?? 0);

        if ($basis === PayBasis::Monthly) {
            return [
                'basis' => $basis,
                /**
                 * A month's salary, cut into as many pieces as the month has
                 * runs.
                 *
                 * This was `first ? rate/2 : rate - rate/2`, which is correct
                 * for a fortnightly payroll and silently wrong for any other.
                 * On a firm cutting off three times a month the first run paid
                 * half a salary and the other two paid half **each** — so
                 * everybody was paid one and a half times what they earn, and
                 * no single payslip looked wrong.
                 */
                'basic_cents' => MonthlyShare::forRun($rate, $cutoff['index'], $cutoff['count']),
                'monthly_equivalent_cents' => $rate,
                'days_worked' => 0,
                'sheet_days' => 0,
                'trips' => 0,
            ];
        }

        // Read for the whole run in one pass and handed in — see `build()`.
        // Nothing for this person is an ordinary answer, not a missing one:
        // they simply did not work this period.
        $sheet ??= ['earned_cents' => 0, 'days' => 0, 'trips' => 0];

        // The rate times what the period actually holds. Days off the truck
        // sheet for a daily hand, hauls off the trip record for a per-trip one.
        $basic = $basis === PayBasis::Daily
            ? $sheet['days'] * $rate
            : $sheet['trips'] * $rate;

        // How many periods make a month, from the firm's own calendar — so a
        // monthly payroll multiplies by one rather than assuming two.
        $perMonth = max(1, $this->calendar()->runsPerMonth());

        return [
            'basis' => $basis,
            'basic_cents' => $basic,
            'monthly_equivalent_cents' => $basic * $perMonth,
            // Only meaningful on a daily basis, where it is what the rate was
            // multiplied by. Zero elsewhere rather than a number nothing reads.
            'days_worked' => $basis === PayBasis::Daily ? $sheet['days'] : 0,
            // How many sheet days the figure came from. On a per-trip payslip
            // this is the count somebody checks the trips against.
            'sheet_days' => $sheet['days'],
            // What a per-trip rate was multiplied by.
            'trips' => $basis === PayBasis::PerTrip ? $sheet['trips'] : 0,
        ];
    }

    /**
     * The firm's own policy on which cutoff the contributions come off.
     *
     * A column on the company rather than configuration, because two firms in
     * the same yard answer it differently and neither is wrong. Falls back to
     * splitting, which is what payroll did before the setting existed.
     */
    public function schedule(): DeductionSchedule
    {
        return $this->tenant->company()?->payroll_deduct_on ?? DeductionSchedule::Split;
    }

    /**
     * The calendar this firm's payroll runs on.
     *
     * Read off the company for the same reason the deduction schedule is, and
     * it is the more important of the two: the cutoff days used to come from an
     * environment variable, which meant one answer for every haulier on the
     * install. Falls back to the configured default for a company that has
     * never set its own, so nothing about an existing install changes until
     * somebody chooses otherwise.
     *
     * The one place the rest of payroll should get a calendar from — the
     * controller, the resource and `PayRun` all come through here rather than
     * each resolving a company of their own.
     */
    public function calendar(): PayrollCalendar
    {
        return PayrollCalendar::for($this->tenant->company());
    }

    /**
     * Correct one line — an allowance, some overtime, a cash advance.
     *
     * The escape hatch that makes this usable without a timekeeping module,
     * and the one that makes the statutory approximations safe: an office that
     * knows the right SSS figure types the right figure.
     *
     * The tax is **not** recomputed from the new gross. That is deliberate and
     * worth stating: an adjustment is usually a one-off that the BIR table
     * would tax as if it were the person's regular pay, and an office
     * correcting a payslip has not asked for the tax to move under them. A run
     * that needs the tax redone is rebuilt.
     *
     * @param  array<string, mixed>  $changes
     */
    public function adjustLine(PayRunLine $line, array $changes): PayRunLine
    {
        $this->mustBeOpen($line->payRun);

        $line->fill(array_intersect_key($changes, array_flip([
            'allowance_cents', 'overtime_cents', 'adjustments_cents', 'adjustment_note',
            'sss_cents', 'philhealth_cents', 'pagibig_cents', 'withholding_tax_cents',
            'other_deductions_cents', 'deduction_note',
        ])));

        return $this->settleTotals($line);
    }

    /**
     * Write the three stored totals from the parts, and save.
     *
     * The only place they are written. They are stored because they are what
     * somebody was handed on paper — see `PayRunLine` — and one writer is what
     * keeps them agreeing with the columns beside them.
     */
    /**
     * The rows somebody typed onto a payslip, keyed by employee.
     *
     * Read **before** the lines are deleted, in the shape the catalogue's own
     * components arrive in, so `writeLine()` cannot tell the two apart. That is
     * the point: a hand-added deduction goes through the same totals and the
     * same tax base as an assigned one rather than being stapled on afterwards,
     * and a rebuilt payslip adds up for the same reason the first one did.
     *
     * Keyed by employee rather than by line because the lines themselves are
     * about to stop existing.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function handAddedComponents(PayRun $run): array
    {
        $kept = [];

        foreach ($run->lines()->with('components')->get() as $line) {
            foreach ($line->components as $component) {
                if (! $component->added_by_hand) {
                    continue;
                }

                $kept[$line->employee_id][] = [
                    'component' => null,
                    'pay_component_id' => $component->pay_component_id,
                    'name' => $component->name,
                    'kind' => $component->kind->value,
                    'taxable' => (bool) $component->taxable,
                    'amount_cents' => (int) $component->amount_cents,
                    'added_by_hand' => true,
                ];
            }
        }

        return $kept;
    }

    /**
     * Put a deduction on one payslip, for this run only.
     *
     * The everyday case the catalogue cannot answer: a uniform charged to one
     * driver this fortnight, a breakage, a cash advance nobody is going to set
     * up an assignment for. It names itself on the payslip rather than
     * disappearing into the single "other deductions" figure, which is the
     * whole reason to bother — a person holding a payslip that says
     * "₱3,450 other" has no way to ask about the ₱450 part of it.
     *
     * **Deductions only**, and that is a correctness rule rather than a
     * simplification. A taxable earning belongs in the tax base, and the
     * withholding on this line was worked out when the run was built — adding
     * one here would leave the tax saying something the gross no longer
     * supports. An earning is added by working the run out again, which
     * recomputes the lot.
     *
     * @param  array{pay_component_id?: ?string, name?: ?string, amount_cents: int}  $data
     */
    public function addDeduction(PayRunLine $line, array $data): PayRunLine
    {
        $this->mustBeOpen($line->payRun);

        $catalogue = isset($data['pay_component_id'])
            ? PayComponent::find($data['pay_component_id'])
            : null;

        abort_if(
            $catalogue !== null && $catalogue->kind !== PayComponentKind::Deduction,
            422,
            'That is an earning. Only deductions can be added to a payslip once the run is open — '
            .'an earning changes the tax base, so it goes on by working the run out again.',
        );

        $name = trim((string) ($data['name'] ?? $catalogue?->name ?? ''));

        abort_if($name === '', 422, 'Give the deduction a name, or pick one from the list.');

        PayRunLineComponent::create([
            'pay_run_line_id' => $line->getKey(),
            'pay_component_id' => $catalogue?->getKey(),
            'name' => $name,
            'kind' => PayComponentKind::Deduction->value,
            'taxable' => false,
            'amount_cents' => max(0, (int) $data['amount_cents']),
            'added_by_hand' => true,
        ]);

        return $this->recountComponents($line->refresh());
    }

    /**
     * Take one back off.
     *
     * Only the hand-added ones. A row that came from an assignment is the
     * catalogue's answer to this period, and removing it here would be a
     * correction that the next rebuild silently undoes — the office ends the
     * assignment instead, which is the change that lasts.
     */
    public function removeDeduction(PayRunLineComponent $component): PayRunLine
    {
        $line = $component->line;

        $this->mustBeOpen($line->payRun);

        abort_unless(
            $component->added_by_hand,
            422,
            'This one comes from the salary structure. End the assignment to stop it, or it will '
            .'be back the next time the run is worked out.',
        );

        $component->delete();

        return $this->recountComponents($line->refresh());
    }

    /**
     * Add the itemised rows up onto the line, then settle the three totals.
     *
     * The writer for `component_earnings_cents` and `component_deductions_cents`
     * on a line that already exists — `writeLine()` is the writer at insert
     * time, where the figures are needed before there is a row to read them
     * back from. The same split `settleTotals()` and `totalsOn()` make, and for
     * the same reason.
     */
    private function recountComponents(PayRunLine $line): PayRunLine
    {
        $components = $line->components()->get();

        $line->component_earnings_cents = (int) $components
            ->where('kind', PayComponentKind::Earning)
            ->sum('amount_cents');

        $line->component_deductions_cents = (int) $components
            ->where('kind', PayComponentKind::Deduction)
            ->sum('amount_cents');

        return $this->settleTotals($line);
    }

    private function settleTotals(PayRunLine $line): PayRunLine
    {
        $this->totalsOn($line)->save();

        return $line->refresh();
    }

    /**
     * Write the three totals onto the line without saving it.
     *
     * Split out so building a run can set them on a line that has not been
     * inserted yet and save **once**, rather than inserting and immediately
     * updating. The totals still have exactly one writer, which is the whole
     * point of `settleTotals` — this is that writer, and the method above is
     * the version for a line that already exists.
     */
    private function totalsOn(PayRunLine $line): PayRunLine
    {
        $line->gross_cents = $line->computedGrossCents();
        $line->deductions_cents = $line->computedDeductionsCents();
        $line->net_cents = $line->computedNetCents();

        return $line;
    }

    /**
     * Freeze the run. Payslips can go out from here.
     *
     * A run with nobody on it is refused: an approved payroll that pays nobody
     * is a thing somebody will look for later and fail to explain.
     */
    public function approve(PayRun $run, ?User $author = null): PayRun
    {
        $this->mustBeOpen($run);

        $run->load('lines.components');

        abort_if(
            $run->lines->isEmpty(),
            422,
            'This run has nobody on it. Build it again — only active employees with a basic salary on record are paid here.',
        );

        $run->forceFill([
            'status' => PayRun::APPROVED,
            'approved_at' => now(),
            'approved_by' => $author?->id,
        ])->save();

        /**
         * The store tab learns about the run here, and nowhere earlier.
         *
         * Not on build, and that is the whole of why: a draft exists to be
         * worked out again, and a repayment written at build time would be
         * subtracted from the balance the next rebuild reads. Pressing "work
         * out again" twice would settle the tab twice and hand the person the
         * difference.
         *
         * Approving is the point at which the figures stop moving, so it is
         * the point at which anything outside the run may act on them.
         */
        $this->storeCredit->settle($run->lines, $run->pay_date ?? now());

        $this->notifications->pushToRoles(
            roles: [Role::Administrator, Role::Accountant],
            icon: 'badge',
            title: "Payroll {$run->reference} approved",
            detail: sprintf(
                '%s · %d staff · net ₱%s',
                $run->periodLabel(),
                $run->lines->count(),
                number_format($run->netCents() / 100, 2),
            ),
            tone: Tone::Info,
        );

        return $run->refresh()->load('lines.components');
    }

    /**
     * The money has gone out — record it, and post the run to the books.
     *
     * The posting is the point. Until now nothing in this system wrote itself
     * into the journal; a paid payroll is the first document that does, and the
     * entry it writes is the one an accountant would write by hand:
     *
     *     Dr  Salaries expense           the whole gross
     *       Cr  SSS/PhilHealth/Pag-IBIG payable    the contributions
     *       Cr  Withholding tax payable            the tax
     *       Cr  Cash in bank                       what the staff were handed
     *
     * One entry, balanced by construction — the gross *is* the deductions plus
     * the net — and posted, because the money has moved. If the chart is
     * missing an account the run needs, it is refused with the code named
     * rather than posted half way.
     */
    public function markPaid(PayRun $run, ?User $author = null): PayRun
    {
        abort_unless(
            $run->isApproved(),
            422,
            $run->isPaid()
                ? "{$run->reference} has already been paid."
                : 'Approve the run before paying it — approving is what freezes the figures.',
        );

        $run->load('lines.components');

        return DB::transaction(function () use ($run, $author): PayRun {
            $entry = $this->post($run, $author);

            $run->forceFill([
                'status' => PayRun::PAID,
                'paid_at' => now(),
                'journal_entry_id' => $entry?->getKey(),
            ])->save();

            return $run->refresh()->load(['lines.components', 'journalEntry']);
        });
    }

    /**
     * The journal entry a paid run writes.
     *
     * Null when the chart has no accounts to post to at all — an install that
     * has not seeded one. That is reported by `markPaid` rather than throwing:
     * the payroll itself is real and recorded, and refusing to record that the
     * staff were paid because the bookkeeping is not set up would be the wrong
     * way round.
     */
    private function post(PayRun $run, ?User $author): ?JournalEntry
    {
        $codes = (array) config('cargo.payroll.accounts', []);

        $accounts = Account::query()
            ->whereIn('code', array_values($codes))
            ->get()
            ->keyBy('code');

        if ($accounts->isEmpty()) {
            return null;
        }

        $statutory = $run->statutoryCents();
        $contributions = $statutory['sss'] + $statutory['philhealth'] + $statutory['pagibig'];
        $other = $statutory['other'];

        $lines = [];

        $push = function (string $code, string $side, int $amount, string $memo) use (&$lines, $accounts): void {
            if ($amount <= 0) {
                return;
            }

            $account = $accounts->get($code);

            abort_if(
                $account === null,
                422,
                sprintf('Account %s is missing from the chart, so this run cannot be posted.', $code),
            );

            $lines[] = [
                'account_id' => $account->getKey(),
                'side' => $side,
                'amount_cents' => $amount,
                'memo' => $memo,
            ];
        };

        // The whole cost of employing people this period, on one debit.
        $push($codes['salaries_expense'] ?? '5200', 'debit', $run->grossCents(), 'Salaries and wages');

        // What each agency is now owed, and what the staff were handed.
        $push($codes['statutory_payable'] ?? '2200', 'credit', $contributions, 'SSS, PhilHealth and Pag-IBIG withheld');
        $push($codes['withholding_payable'] ?? '2160', 'credit', $statutory['withholding_tax'], 'Withholding tax on wages');
        // Anything the firm is recovering — a cash advance — reduces what goes
        // out and lands against wages payable rather than cash.
        $push($codes['accrued_wages'] ?? '2100', 'credit', $other, 'Advances and other deductions recovered');
        $push($codes['cash'] ?? '1020', 'credit', $run->netCents(), 'Net pay');

        return $this->journal->create(JournalEntryData::fromArray([
            'entry_date' => $run->pay_date?->toDateString() ?? now()->toDateString(),
            'category' => JournalCategory::Payroll->value,
            'memo' => sprintf('Payroll %s · %s', $run->reference, $run->periodLabel()),
            'status' => JournalEntry::POSTED,
            // What raised it, so the entry can be traced back and so the same
            // run can never post twice — the unique index on the pair is what
            // enforces that.
            'source' => 'payroll',
            'source_type' => PayRun::class,
            'source_id' => $run->getKey(),
            'lines' => $lines,
        ]), $author);
    }

    /** Delete a draft. An approved run has been shown to people. */
    public function delete(PayRun $run): void
    {
        $this->mustBeOpen($run);

        DB::transaction(function () use ($run): void {
            $run->lines()->delete();
            $run->delete();
        });
    }

    /**
     * Is this run still somebody's draft?
     *
     * The gate on every write after the first, and the message names the way
     * forward: a run that needs changing after approval is not a mistake, it is
     * a decision to make in two steps.
     */
    private function mustBeOpen(PayRun $run): void
    {
        abort_if($run->isPaid(), 422, sprintf(
            '%s has been paid and is in the books. Correct it with a journal entry rather than by editing the run.',
            $run->reference,
        ));

        abort_if($run->isApproved(), 422, sprintf(
            '%s is approved, so its figures are frozen — payslips may already be out. '
            .'Build a new run, or post an adjustment.',
            $run->reference,
        ));
    }
}
