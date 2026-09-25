<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Hr\Models\Applicant;
use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Models\LeaveRequest;
use App\Domain\Hr\Models\UndertimeRequest;
use App\Domain\Hr\Services\ContractService;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\EmployeePayComponent;
use App\Domain\Payroll\Models\PayComponent;
use App\Domain\Payroll\Models\PayrollCutoffRequest;
use App\Domain\Payroll\Models\StoreCredit;
use App\Domain\Shared\Enums\PayComponentBasis;
use App\Domain\Shared\Enums\PayComponentKind;
use App\Domain\Shared\Enums\PayComponentSchedule;
use App\Domain\Shared\Enums\RequestStatus;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\StoreCreditKind;
use Database\Seeders\Concerns\AdoptsTrashedRows;
use Database\Seeders\Concerns\SeedsIntoACompany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The office: a roster with some variety in it, and the paperwork that follows.
 *
 *     php artisan db:seed --class="Database\Seeders\Demo\PeopleSeeder"
 *
 * `PayrollSeeder` hires the four people a payroll run needs to be worth
 * reading. This is what the HR half of the system is actually made of and none
 * of which a payroll needs: applications, time off, the store tab, the standing
 * allowances and deductions, and a request to move the cutoff.
 *
 * Run it after `PayrollSeeder`, which is where the staff it hangs all of this
 * on come from. On its own it seeds the extra roster and warns about the rest
 * rather than inventing employees the payroll demo would then pay twice.
 *
 * ## Every screen gets all of its states
 *
 * Time off, undertime and the cutoff request share one `RequestStatus`, and a
 * demo where everything is approved shows a queue with nothing in it. So each
 * list below has something pending, something approved and something refused —
 * the pending rows are what the approver's screen is for, and they are the ones
 * a walkthrough clicks.
 *
 * ## Idempotent
 *
 * Staff match on their employee number, applicants on their name, a request on
 * the person and what they wrote, a store line on the person and what they
 * took, components on their name. **Never on a date**: every date here is
 * relative to today, so a key that included one would find nothing tomorrow and
 * file the whole lot again. Running it twice tops up rather than doubling
 * anybody's tab.
 */
class PeopleSeeder extends Seeder
{
    use AdoptsTrashedRows, SeedsIntoACompany;

    /**
     * The rest of the roster: number, first, last, position, type, status,
     * department, hired, contact.
     *
     * A trainee, a probationary, a contractual and somebody who has left.
     * Employment type is what decides which of a job's three rates a new hire
     * starts on, so a roster of nothing but regulars hides the whole tier
     * system — and a resigned employee is what proves the payroll picks up the
     * active ones rather than everybody who ever worked here.
     */
    private const STAFF = [
        ['DEMO-05', 'Cristina', 'Rivera', 'Dispatcher', 'regular', 'active', 'Operations', '2026-02-16'],
        ['DEMO-06', 'Alvin', 'Tagalog', 'Helper', 'probationary', 'active', 'Operations', '2026-06-01'],
        ['DEMO-07', 'Mylene', 'Cabahug', 'Office Staff', 'trainee', 'active', 'Administration', '2026-08-18'],
        ['DEMO-08', 'Bernard', 'Sulit', 'Mechanic', 'contractual', 'active', 'Workshop', '2026-04-05'],
        ['DEMO-09', 'Hazel', 'Ocampo', 'Office Staff', 'regular', 'inactive', 'Administration', '2025-09-15'],
    ];

    /**
     * first, last, job applied for, contact, source, days ago, stage, rating.
     *
     * One at every stage the pipeline has, because the applicants board is a
     * board — columns with nothing in them demonstrate the columns and not the
     * board.
     */
    private const APPLICANTS = [
        ['Jomar', 'Ruiz', 'Driver', '0917 555 0601', 'Walk-in', 2, 'applied', null],
        ['Precious', 'Lim', 'Office Staff', '0917 555 0602', 'Referral — C. Rivera', 5, 'screening', 3],
        ['Edgar', 'Nacua', 'Driver', '0917 555 0603', 'Facebook post', 9, 'interview', 4],
        ['Rowena', 'Bacus', 'Accountant', '0917 555 0604', 'JobStreet', 14, 'offered', 5],
        ['Ferdinand', 'Yap', 'Helper', '0917 555 0605', 'Walk-in', 21, 'hired', 4],
        ['Melvin', 'Dizon', 'Driver', '0917 555 0606', 'Walk-in', 26, 'rejected', 2],
    ];

    /** employee no, type, from, to, days, reason, status */
    private const LEAVE = [
        ['DEMO-05', 'vacation', 6, 10, 5.0, 'Family trip to Camiguin.', 'pending'],
        ['DEMO-03', 'sick', -3, -2, 2.0, 'Fever and cough, with a medical certificate.', 'approved'],
        ['DEMO-06', 'emergency', -9, -9, 1.0, 'Flooding at home in Bulua.', 'approved'],
        ['DEMO-08', 'unpaid', 12, 19, 8.0, 'Personal matter in Davao.', 'rejected'],
        ['DEMO-07', 'bereavement', -16, -14, 3.0, 'Death in the family.', 'approved'],
    ];

    /** employee no, days from today, from, to, hours, reason, status */
    private const UNDERTIME = [
        ['DEMO-07', 1, '15:00', '17:00', 2.0, 'Barangay clearance appointment.', 'pending'],
        ['DEMO-05', -4, '14:30', '17:00', 2.5, 'Dental appointment.', 'approved'],
        ['DEMO-06', -11, '16:00', '17:00', 1.0, 'Left early, no reason given at the time.', 'rejected'],
    ];

    /**
     * The store tab: employee no, kind, pesos, what for, outlet, days ago.
     *
     * Charges and a payment against them, because the balance is the difference
     * and a tab of nothing but charges never shows anybody paying one down.
     */
    private const STORE = [
        ['DEMO-03', 'charge', 1450, 'Rice, 25kg', 'Yard canteen', -18],
        ['DEMO-03', 'charge', 620, 'Cooking oil and canned goods', 'Yard canteen', -11],
        ['DEMO-03', 'payment', 1000, 'Paid at the counter', 'Yard canteen', -4],
        ['DEMO-04', 'charge', 2300, 'Cash advance against pay', 'Office', -13],
        ['DEMO-06', 'charge', 780, 'Work gloves and boots', 'Yard canteen', -7],
    ];

    /**
     * The standing allowances and deductions: name, kind, basis, pesos,
     * rate in basis points, schedule, taxable.
     *
     * A fixed rice allowance and a percentage COLA are the two shapes a
     * component comes in, and having one of each is what makes the payslip's
     * component lines worth reading.
     */
    private const COMPONENTS = [
        ['Rice allowance', 'earning', 'fixed', 2000, 0, 'monthly_split', false],
        ['COLA', 'earning', 'percent_of_basic', 0, 500, 'monthly_split', true],
        ['Uniform deduction', 'deduction', 'fixed', 250, 0, 'first_cutoff', false],
        ['SSS salary loan', 'deduction', 'fixed', 1200, 0, 'second_cutoff', false],
    ];

    /** Who is on what: employee no, component name, pesos or null for the catalogue figure. */
    private const ASSIGNMENTS = [
        ['DEMO-01', 'Rice allowance', null],
        ['DEMO-01', 'COLA', null],
        ['DEMO-02', 'Rice allowance', null],
        ['DEMO-02', 'SSS salary loan', null],
        ['DEMO-05', 'Rice allowance', null],
        // On a figure of their own, which is the reason an assignment may carry
        // an amount at all: the catalogue says what the firm usually pays, and
        // somebody's own arrangement overrides it without editing the rule.
        ['DEMO-05', 'Uniform deduction', 150],
    ];

    public function __construct(private readonly ContractService $contracts) {}

    public function run(): void
    {
        $this->intoCompany(function (): void {
            $this->staff();
            $this->applicants();

            if (Employee::query()->count() === 0) {
                $this->command?->warn('No staff to hang HR records on — run PayrollSeeder first.');

                return;
            }

            $this->timeOff();
            $this->storeTab();
            $this->components();
            $this->cutoffRequest();

            $this->report();
        });
    }

    private function staff(): void
    {
        $jobs = Position::query()->get()->keyBy('name');

        foreach (self::STAFF as [$number, $first, $last, $job, $type, $status, $department, $hired]) {
            $position = $jobs[$job] ?? null;

            $employee = $this->restoreOrCreate(Employee::class, ['employee_no' => $number], [
                'first_name' => $first,
                'last_name' => $last,
                'position' => $job,
                'position_id' => $position?->getKey(),
                'department' => $department,
                'employment_type' => $type,
                'status' => $status,
                'hired_on' => $hired,
                'contact' => '0917 555 0'.substr($number, -3),
                'email' => strtolower($first).'.'.strtolower($last).'@cargorush.ph',
                'sss_enrolled' => true,
                'philhealth_enrolled' => true,
                'pagibig_enrolled' => true,
            ]);

            // Opened off the job at the tier their employment type puts them
            // on, exactly as hiring through the form would — and only where
            // there is not one already, so a re-run cannot append a second
            // contract and silently restate somebody's pay.
            if ($position !== null && $employee->contractOn() === null) {
                $this->contracts->openFromPosition($employee, $position, $employee->hired_on);
            }
        }
    }

    private function applicants(): void
    {
        foreach (self::APPLICANTS as [$first, $last, $job, $contact, $source, $days, $stage, $rating]) {
            $appliedOn = Carbon::today()->subDays($days);

            /**
             * Keyed on the name, with the day it came in as an ordinary value.
             *
             * Not on the date, although the date is what makes it this
             * application: every date here is relative to today, so a seeder
             * run again tomorrow would find nothing matching and file the same
             * six people a second time. The same reasoning applies to the leave,
             * undertime and store rows below.
             */
            $this->restoreOrCreate(Applicant::class,
                ['first_name' => $first, 'last_name' => $last],
                [
                    'applied_on' => $appliedOn->toDateString(),
                    'position_applied' => $job,
                    'contact' => $contact,
                    'email' => strtolower($first).'.'.strtolower($last).'@example.ph',
                    'address' => 'Cagayan de Oro',
                    'source' => $source,
                    'stage' => $stage,
                    'rating' => $rating,
                    // Only a decided application carries a decision date, which
                    // is what separates a board somebody is working through
                    // from a pile of closed files.
                    'decided_at' => in_array($stage, ['hired', 'rejected'], true)
                        ? $appliedOn->copy()->addDays(6)
                        : null,
                ],
            );
        }
    }

    private function timeOff(): void
    {
        $staff = Employee::query()->pluck('id', 'employee_no');
        $approver = User::query()->where('email', 'admin@cargorush.ph')->value('id');

        foreach (self::LEAVE as [$number, $type, $from, $to, $days, $reason, $status]) {
            $employee = $staff[$number] ?? null;

            if ($employee === null) {
                continue;
            }

            $starts = Carbon::today()->addDays($from);

            $this->restoreOrCreate(LeaveRequest::class,
                ['employee_id' => $employee, 'reason' => $reason],
                [
                    'starts_on' => $starts->toDateString(),
                    'type' => $type,
                    'ends_on' => Carbon::today()->addDays($to)->toDateString(),
                    'days' => $days,
                    'reason' => $reason,
                    'status' => $status,
                    'decided_by' => $status === RequestStatus::Pending->value ? null : $approver,
                    'decided_at' => $status === RequestStatus::Pending->value ? null : $starts->copy()->subDays(2),
                    'decision_note' => $status === RequestStatus::Rejected->value
                        ? 'Two drivers already off that week.'
                        : null,
                ],
            );
        }

        foreach (self::UNDERTIME as [$number, $days, $fromTime, $toTime, $hours, $reason, $status]) {
            $employee = $staff[$number] ?? null;

            if ($employee === null) {
                continue;
            }

            $date = Carbon::today()->addDays($days);

            $this->restoreOrCreate(UndertimeRequest::class,
                ['employee_id' => $employee, 'reason' => $reason],
                [
                    'date' => $date->toDateString(),
                    'from_time' => $fromTime,
                    'to_time' => $toTime,
                    'hours' => $hours,
                    'reason' => $reason,
                    'status' => $status,
                    'decided_by' => $status === RequestStatus::Pending->value ? null : $approver,
                    'decided_at' => $status === RequestStatus::Pending->value ? null : $date->copy()->subDay(),
                ],
            );
        }
    }

    /**
     * The *pautang* ledger.
     *
     * Charges and payments, left unsettled on purpose: what a cutoff takes off
     * a payslip is capped by `store_deduction_cap_cents` on the employee, and a
     * tab that is already square would never show the cap doing anything.
     */
    private function storeTab(): void
    {
        $staff = Employee::query()->pluck('id', 'employee_no');

        foreach (self::STORE as [$number, $kind, $peso, $description, $outlet, $days]) {
            $employee = $staff[$number] ?? null;

            if ($employee === null) {
                continue;
            }

            $on = Carbon::today()->addDays($days);

            $this->restoreOrCreate(StoreCredit::class,
                ['employee_id' => $employee, 'description' => $description],
                [
                    'charged_on' => $on->toDateString(),
                    'kind' => $kind === 'charge' ? StoreCreditKind::Charge->value : StoreCreditKind::Payment->value,
                    'amount_cents' => $peso * 100,
                    'outlet' => $outlet,
                ],
            );
        }

        // A ceiling on what a single payslip may take, so somebody with a large
        // tab still goes home with something. ₱2,000 a cutoff.
        Employee::query()->where('store_deduction_cap_cents', 0)->update([
            'store_deduction_cap_cents' => 200000,
        ]);
    }

    private function components(): void
    {
        foreach (self::COMPONENTS as $order => [$name, $kind, $basis, $peso, $rateBp, $schedule, $taxable]) {
            $this->restoreOrCreate(PayComponent::class, ['name' => $name], [
                'kind' => PayComponentKind::from($kind)->value,
                'basis' => PayComponentBasis::from($basis)->value,
                'amount_cents' => $peso * 100,
                'rate_bp' => $rateBp,
                'schedule' => PayComponentSchedule::from($schedule)->value,
                'taxable' => $taxable,
                'status' => StatusValue::Active->value,
                'position' => $order,
            ]);
        }

        $staff = Employee::query()->pluck('id', 'employee_no');
        $catalogue = PayComponent::query()->pluck('id', 'name');

        foreach (self::ASSIGNMENTS as [$number, $name, $peso]) {
            $employee = $staff[$number] ?? null;
            $component = $catalogue[$name] ?? null;

            if ($employee === null || $component === null) {
                continue;
            }

            $this->restoreOrCreate(EmployeePayComponent::class,
                ['employee_id' => $employee, 'pay_component_id' => $component],
                [
                    // Null means "whatever the catalogue says", which is the
                    // ordinary case: change the rice allowance once and
                    // everybody on it moves.
                    'amount_cents' => $peso === null ? null : $peso * 100,
                    'effective_from' => Carbon::today()->startOfYear()->toDateString(),
                    'status' => StatusValue::Active->value,
                ],
            );
        }
    }

    /**
     * A request to move the cutoff, sitting with whoever decides it.
     *
     * The cutoff days are a company setting that payroll reads for every
     * period, so changing them is not something one screen should do quietly —
     * it is asked for, with a reason, and approved. Seeded pending, because the
     * point of the feature is the decision.
     */
    private function cutoffRequest(): void
    {
        $company = $this->seedCompany();
        $requester = User::query()->where('email', 'accounts@cargorush.ph')->value('id');

        $already = PayrollCutoffRequest::query()
            ->where('status', RequestStatus::Pending->value)
            ->exists();

        if ($already) {
            return;
        }

        PayrollCutoffRequest::create([
            'cutoff_days' => [10, 25],
            'payroll_deduct_on' => 'second',
            'reason' => 'The crew are asking for the 10th and the 25th so pay lands before the rent.',
            'status' => RequestStatus::Pending->value,
            'requested_by' => $requester,
            'previous_cutoff_days' => $company->payroll_cutoff_days,
        ]);
    }

    private function report(): void
    {
        $this->command?->info(sprintf(
            '  %d staff, %d applicants, %d leave and %d undertime requests, %d pay components.',
            Employee::query()->count(),
            Applicant::query()->count(),
            LeaveRequest::query()->count(),
            UndertimeRequest::query()->count(),
            PayComponent::query()->count(),
        ));
    }
}
