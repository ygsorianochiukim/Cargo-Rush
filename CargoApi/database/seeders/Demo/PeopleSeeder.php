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

class PeopleSeeder extends Seeder
{
    use AdoptsTrashedRows, SeedsIntoACompany;

    private const STAFF = [
        ['DEMO-05', 'Cristina', 'Rivera', 'Dispatcher', 'regular', 'active', 'Operations', '2026-02-16'],
        ['DEMO-06', 'Alvin', 'Tagalog', 'Helper', 'probationary', 'active', 'Operations', '2026-06-01'],
        ['DEMO-07', 'Mylene', 'Cabahug', 'Office Staff', 'trainee', 'active', 'Administration', '2026-08-18'],
        ['DEMO-08', 'Bernard', 'Sulit', 'Mechanic', 'contractual', 'active', 'Workshop', '2026-04-05'],
        ['DEMO-09', 'Hazel', 'Ocampo', 'Office Staff', 'regular', 'inactive', 'Administration', '2025-09-15'],
    ];

    private const APPLICANTS = [
        ['Jomar', 'Ruiz', 'Driver', '0917 555 0601', 'Walk-in', 2, 'applied', null],
        ['Precious', 'Lim', 'Office Staff', '0917 555 0602', 'Referral — C. Rivera', 5, 'screening', 3],
        ['Edgar', 'Nacua', 'Driver', '0917 555 0603', 'Facebook post', 9, 'interview', 4],
        ['Rowena', 'Bacus', 'Accountant', '0917 555 0604', 'JobStreet', 14, 'offered', 5],
        ['Ferdinand', 'Yap', 'Helper', '0917 555 0605', 'Walk-in', 21, 'hired', 4],
        ['Melvin', 'Dizon', 'Driver', '0917 555 0606', 'Walk-in', 26, 'rejected', 2],
    ];

    private const LEAVE = [
        ['DEMO-05', 'vacation', 6, 10, 5.0, 'Family trip to Camiguin.', 'pending'],
        ['DEMO-03', 'sick', -3, -2, 2.0, 'Fever and cough, with a medical certificate.', 'approved'],
        ['DEMO-06', 'emergency', -9, -9, 1.0, 'Flooding at home in Bulua.', 'approved'],
        ['DEMO-08', 'unpaid', 12, 19, 8.0, 'Personal matter in Davao.', 'rejected'],
        ['DEMO-07', 'bereavement', -16, -14, 3.0, 'Death in the family.', 'approved'],
    ];

    private const UNDERTIME = [
        ['DEMO-07', 1, '15:00', '17:00', 2.0, 'Barangay clearance appointment.', 'pending'],
        ['DEMO-05', -4, '14:30', '17:00', 2.5, 'Dental appointment.', 'approved'],
        ['DEMO-06', -11, '16:00', '17:00', 1.0, 'Left early, no reason given at the time.', 'rejected'],
    ];

    private const STORE = [
        ['DEMO-03', 'charge', 1450, 'Rice, 25kg', 'Yard canteen', -18],
        ['DEMO-03', 'charge', 620, 'Cooking oil and canned goods', 'Yard canteen', -11],
        ['DEMO-03', 'payment', 1000, 'Paid at the counter', 'Yard canteen', -4],
        ['DEMO-04', 'charge', 2300, 'Cash advance against pay', 'Office', -13],
        ['DEMO-06', 'charge', 780, 'Work gloves and boots', 'Yard canteen', -7],
    ];

    private const COMPONENTS = [
        ['Rice allowance', 'earning', 'fixed', 2000, 0, 'monthly_split', false],
        ['COLA', 'earning', 'percent_of_basic', 0, 500, 'monthly_split', true],
        ['Uniform deduction', 'deduction', 'fixed', 250, 0, 'first_cutoff', false],
        ['SSS salary loan', 'deduction', 'fixed', 1200, 0, 'second_cutoff', false],
    ];

    private const ASSIGNMENTS = [
        ['DEMO-01', 'Rice allowance', null],
        ['DEMO-01', 'COLA', null],
        ['DEMO-02', 'Rice allowance', null],
        ['DEMO-02', 'SSS salary loan', null],
        ['DEMO-05', 'Rice allowance', null],
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

            if ($position !== null && $employee->contractOn() === null) {
                $this->contracts->openFromPosition($employee, $position, $employee->hired_on);
            }
        }
    }

    private function applicants(): void
    {
        foreach (self::APPLICANTS as [$first, $last, $job, $contact, $source, $days, $stage, $rating]) {
            $appliedOn = Carbon::today()->subDays($days);

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
                    'amount_cents' => $peso === null ? null : $peso * 100,
                    'effective_from' => Carbon::today()->startOfYear()->toDateString(),
                    'status' => StatusValue::Active->value,
                ],
            );
        }
    }

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
