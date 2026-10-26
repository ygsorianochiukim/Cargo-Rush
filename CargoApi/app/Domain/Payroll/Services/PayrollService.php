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
use App\Domain\Payroll\Models\PayRunLineTrip;
use App\Domain\Payroll\Support\MonthlyShare;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Shared\Enums\CrewEmployment;
use App\Domain\Shared\Enums\DeductionSchedule;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\PayComponentKind;
use App\Domain\Shared\Enums\PayrollScope;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        private readonly HiredOutCrew $hiredOut,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return PayRun::query()
            ->with(['lines.components', 'approvedBy:id,name', 'journalEntry:id,reference'])
            ->when(
                ! empty($filters['scope']),
                fn ($query) => $query->ofScope(PayrollScope::from((string) $filters['scope'])),
            )
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
            ->with([
                'lines.components',
                'lines.employee:id,employee_no,first_name,last_name,driver_id',
                'lines.tripLinks.trip:id,reference,origin,destination,scheduled_at,driver_id,vehicle_id',
                'lines.tripLinks.trip.vehicle:id,plate',
                'lines.tripLinks.trip.deliveryLog:id,trip_id,delivered_at',
                'approvedBy:id,name',
                'journalEntry:id,reference',
            ])
            ->findOrFail($id);
    }

    public function build(
        Carbon $periodStart,
        Carbon $periodEnd,
        Carbon $payDate,
        ?User $author = null,
        ?PayRun $existing = null,
        PayrollScope $scope = PayrollScope::Office,
    ): PayRun {
        if ($existing !== null) {
            $this->mustBeOpen($existing);
            $scope = $existing->scope();
        }

        return DB::transaction(function () use ($periodStart, $periodEnd, $payDate, $author, $existing, $scope): PayRun {
            $this->lockPayroll();

            if ($existing === null) {
                $this->mustNotOverlap($periodStart, $periodEnd, $scope);
            }

            $run = $existing ?? new PayRun;

            $run->fill([
                'scope' => $scope->value,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'pay_date' => $payDate->toDateString(),
                'status' => PayRun::DRAFT,
                'links_trips' => true,
            ]);

            if ($author !== null && $run->created_by === null) {
                $run->created_by = $author->id;
            }

            $run->save();

            $byHand = $this->handAddedComponents($run);

            PayRunLineTrip::query()
                ->whereIn('pay_run_line_id', $run->lines()->select('id'))
                ->delete();
            $run->lines()->delete();

            $cutoff = $this->calendar()->classify($run->period_start, $run->period_end);
            $schedule = $this->schedule();

            $payable = $this->payable($run->period_end, $scope, $run->period_start);
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

            $tabs = $this->storeCredit->available(
                $payable->modelKeys(),
                $run->period_end->toDateString(),
                $run->getKey(),
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

            return $this->find($run->getKey());
        });
    }

    /** @return Collection<int, Employee> */
    public function payable(Carbon|string|null $on = null, ?PayrollScope $scope = null, Carbon|string|null $from = null)
    {
        $people = $this->onTheBooks($on, $scope);

        if ($scope !== PayrollScope::Crew || $from === null || $on === null) {
            return $people;
        }

        $start = Carbon::parse($from);
        $end = Carbon::parse($on);
        $spans = $this->hiredOut->spansFor($people->pluck('driver_id')->filter()->all(), $start, $end);

        return $people
            ->reject(static fn (Employee $employee): bool => HiredOutCrew::coversWhole($spans[$employee->driver_id] ?? [], $start, $end))
            ->values();
    }

    /** @return Collection<int, Employee> */
    private function onTheBooks(Carbon|string|null $on, ?PayrollScope $scope)
    {
        return Employee::query()
            ->where('status', StatusValue::Active->value)
            ->when($scope === PayrollScope::Office, fn ($query) => $query->whereNull('driver_id'))
            ->when($scope === PayrollScope::Crew, fn ($query) => $query
                ->whereNotNull('driver_id')
                ->whereHas('driver', fn ($driver) => $driver->where('employment', CrewEmployment::Company->value)))
            ->with(['contracts' => fn ($contracts) => $contracts->inForceOn($on)])
            ->orderBy('last_name')
            ->get()
            ->filter(function (Employee $employee) use ($on): bool {
                $contract = $employee->contractOn($on);

                if ($contract === null) {
                    return false;
                }

                $basis = $contract->pay_basis ?? PayBasis::Monthly;

                if (! $basis->countsWork()) {
                    return (int) $contract->amount_cents > 0;
                }

                return $employee->driver_id !== null;
            })
            ->values();
    }

    /** @param array{first: bool, only: bool, index: int, count: int} $cutoff */
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

        $componentEarnings = 0;
        $componentDeductions = 0;
        $taxableEarnings = 0;
        $monthlyTaxableEarnings = 0;

        foreach ($components as $component) {
            if ($component['kind'] === PayComponentKind::Earning->value) {
                $componentEarnings += $component['amount_cents'];

                if ($component['taxable']) {
                    $taxableEarnings += $component['amount_cents'];
                    $monthlyTaxableEarnings += (int) ($component['monthly_cents'] ?? $component['amount_cents']);
                }

                continue;
            }

            $componentDeductions += $component['amount_cents'];
        }

        $basic = $earned['basic_cents'];

        $enrolled = $this->benefitsEnabled()
            ? $employee->statutoryEnrolment()
            : ['sss' => false, 'philhealth' => false, 'pagibig' => false];

        $statutory = $this->deductions->for(
            $monthly,
            $basic + $taxableEarnings,
            $cutoff['index'],
            $cutoff['count'],
            $schedule,
            $enrolled,
            $monthlyTaxableEarnings,
        );

        $employer = $this->deductions->employerFor(
            $monthly,
            $cutoff['index'],
            $cutoff['count'],
            $schedule,
            $enrolled,
        );

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
            'pay_basis' => $earned['basis']->value,
            'crew' => $employee->driver_id !== null,
            'basic_cents' => $basic,
            'days_worked' => $earned['days_worked'],
            'sheet_days' => $earned['sheet_days'],
            'trips' => $earned['trips'],
            'allowance_cents' => 0,
            'overtime_cents' => 0,
            'adjustments_cents' => 0,
            'component_earnings_cents' => $componentEarnings,
            'component_deductions_cents' => $componentDeductions,
            'sss_cents' => $statutory['sss'],
            'philhealth_cents' => $statutory['philhealth'],
            'pagibig_cents' => $statutory['pagibig'],
            'withholding_tax_cents' => $statutory['withholding_tax'],
            'employer_sss_cents' => $employer['sss'],
            'employer_ec_cents' => $employer['ec'],
            'employer_philhealth_cents' => $employer['philhealth'],
            'employer_pagibig_cents' => $employer['pagibig'],
            'sss_enrolled' => $enrolled['sss'],
            'philhealth_enrolled' => $enrolled['philhealth'],
            'pagibig_enrolled' => $enrolled['pagibig'],
            'store_deduction_cents' => $store,
            'other_deductions_cents' => 0,
        ]);

        $this->totalsOn($line)->save();

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

        foreach (array_unique($earned['trip_ids'] ?? []) as $tripId) {
            PayRunLineTrip::create([
                'pay_run_line_id' => $line->getKey(),
                'trip_id' => $tripId,
                'employee_id' => $employee->getKey(),
            ]);
        }

        return $line;
    }

    /**
     * @param  array{first: bool, only: bool, index: int, count: int}  $cutoff
     * @return array{basis: PayBasis, basic_cents: int, monthly_equivalent_cents: int, days_worked: int, sheet_days: int, trips: int, trip_ids: list<string>}
     */
    private function earningsFor(Employee $employee, array $cutoff, ?array $sheet = null, Carbon|string|null $on = null): array
    {
        $contract = $employee->contractOn($on);
        $basis = $contract?->pay_basis ?? PayBasis::Monthly;
        $rate = (int) ($contract?->amount_cents ?? 0);

        if ($basis === PayBasis::Monthly) {
            return [
                'basis' => $basis,
                'basic_cents' => MonthlyShare::forRun($rate, $cutoff['index'], $cutoff['count']),
                'monthly_equivalent_cents' => $rate,
                'days_worked' => 0,
                'sheet_days' => 0,
                'trips' => 0,
                'trip_ids' => $sheet['trip_ids'] ?? [],
            ];
        }

        $sheet ??= ['earned_cents' => 0, 'days' => 0, 'trips' => 0, 'trip_ids' => []];

        $basic = $basis === PayBasis::Daily
            ? $sheet['days'] * $rate
            : $sheet['trips'] * $rate;

        $perMonth = max(1, $this->calendar()->runsPerMonth());

        return [
            'basis' => $basis,
            'basic_cents' => $basic,
            'monthly_equivalent_cents' => $basic * $perMonth,
            'days_worked' => $basis === PayBasis::Daily ? $sheet['days'] : 0,
            'sheet_days' => $sheet['days'],
            'trips' => $basis === PayBasis::PerTrip ? $sheet['trips'] : 0,
            'trip_ids' => $sheet['trip_ids'] ?? [],
        ];
    }

    public function schedule(): DeductionSchedule
    {
        return $this->tenant->company()?->payroll_deduct_on ?? DeductionSchedule::Split;
    }

    public function benefitsEnabled(): bool
    {
        return $this->tenant->company()?->payroll_benefits_enabled ?? true;
    }

    public function calendar(): PayrollCalendar
    {
        return PayrollCalendar::for($this->tenant->company());
    }

    /** @param array<string, mixed> $changes */
    public function adjustLine(PayRunLine $line, array $changes): PayRunLine
    {
        $this->mustBeOpen($line->payRun);

        $line->fill(array_intersect_key($changes, array_flip([
            'allowance_cents', 'overtime_cents', 'adjustments_cents', 'adjustment_note',
            'sss_cents', 'philhealth_cents', 'pagibig_cents', 'withholding_tax_cents',
            'employer_sss_cents', 'employer_ec_cents', 'employer_philhealth_cents', 'employer_pagibig_cents',
            'other_deductions_cents', 'deduction_note',
        ])));

        return $this->settleTotals($line);
    }

    /** @return array<string, list<array<string, mixed>>> */
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

    /** @param array{pay_component_id?: ?string, name?: ?string, amount_cents: int} $data */
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

        return DB::transaction(function () use ($line, $catalogue, $name, $data): PayRunLine {
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
        });
    }

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

        return DB::transaction(function () use ($component, $line): PayRunLine {
            $component->delete();

            return $this->recountComponents($line->refresh());
        });
    }

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
        $this->recapStore($line);
        $this->totalsOn($line);

        abort_if($line->net_cents < 0, 422, sprintf(
            'That would leave %s with a net pay of ₱%s. A payslip cannot pay less than nothing — '
            .'reduce the deduction, or carry the rest to the next run.',
            $line->name,
            number_format($line->net_cents / 100, 2),
        ));

        $line->save();

        return $line->refresh();
    }

    private function recapStore(PayRunLine $line): void
    {
        $employee = $line->employee;
        $run = $line->payRun;

        $wanted = $employee === null || $run === null
            ? (int) $line->store_deduction_cents
            : $this->storeCredit->deductionFor($employee, $this->storeCredit->available(
                [$employee->getKey()],
                $run->period_end->toDateString(),
                $run->getKey(),
            )[$employee->getKey()] ?? 0);

        $room = $line->computedGrossCents()
            - ($line->computedDeductionsCents() - (int) $line->store_deduction_cents);

        $line->store_deduction_cents = min($wanted, max(0, $room));
    }

    private function totalsOn(PayRunLine $line): PayRunLine
    {
        $line->gross_cents = $line->computedGrossCents();
        $line->deductions_cents = $line->computedDeductionsCents();
        $line->net_cents = $line->computedNetCents();

        return $line;
    }

    public function approve(PayRun $run, ?User $author = null): PayRun
    {
        return DB::transaction(fn (): PayRun => $this->approveLocked($run, $author));
    }

    private function approveLocked(PayRun $run, ?User $author): PayRun
    {
        $this->lockPayroll();

        $run = PayRun::query()->lockForUpdate()->findOrFail($run->getKey());

        $this->mustBeOpen($run);

        $run->load('lines.components', 'lines.tripLinks');

        abort_if(
            $run->lines->isEmpty(),
            422,
            $run->isCrewRun()
                ? 'This run has nobody on it. Build it again — only the company’s own drivers and helpers, active and with a rate on their contract, are paid here.'
                : 'This run has nobody on it. Build it again — only active office staff with a basic salary on record are paid here.',
        );

        $this->mustNotOverlap($run->period_start, $run->period_end, $run->scope(), $run);
        $this->mustNotRepayTrips($run);
        $this->capStoreAtApproval($run);

        $negative = $run->lines->first(fn (PayRunLine $line): bool => (int) $line->net_cents < 0);

        abort_if($negative !== null, 422, sprintf(
            '%s’s payslip comes to ₱%s net. Correct the deductions on it before approving — a run '
            .'cannot pay anybody less than nothing, and it could not be put in the books.',
            $negative?->name,
            number_format(((int) $negative?->net_cents) / 100, 2),
        ));

        $run->forceFill([
            'status' => PayRun::APPROVED,
            'approved_at' => now(),
            'approved_by' => $author?->id,
        ])->save();

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

        return $this->find($run->getKey());
    }

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

            return $this->find($run->getKey());
        });
    }

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

        $salaries = $codes['salaries_expense'] ?? '5200';
        $crewWages = $codes['crew_wages_expense'] ?? '5020';

        if (! $accounts->has($crewWages)) {
            $crewWages = $salaries;
        }

        $crewGross = (int) $run->lines->where('crew', true)->sum('gross_cents');

        $push($crewWages, 'debit', $crewGross, 'Drivers’ and helpers’ wages');
        $push($salaries, 'debit', $run->grossCents() - $crewGross, 'Office salaries and wages');

        $crewEmployerAccount = $codes['crew_employer_contributions_expense'] ?? $crewWages;
        $officeEmployerAccount = $codes['employer_contributions_expense'] ?? $salaries;

        if (! $accounts->has($crewEmployerAccount)) {
            $crewEmployerAccount = $crewWages;
        }

        if (! $accounts->has($officeEmployerAccount)) {
            $officeEmployerAccount = $salaries;
        }

        $employer = $run->employerContributionsCents()['total'];
        $crewEmployer = (int) $run->lines->where('crew', true)
            ->sum(static fn (PayRunLine $line): int => $line->employerContributionsCents());

        $push($crewEmployerAccount, 'debit', $crewEmployer, 'SSS, PhilHealth and Pag-IBIG — employer share, drivers and helpers');
        $push($officeEmployerAccount, 'debit', $employer - $crewEmployer, 'SSS, PhilHealth and Pag-IBIG — employer share, office');

        $push($codes['statutory_payable'] ?? '2200', 'credit', $contributions, 'SSS, PhilHealth and Pag-IBIG withheld');
        $push($codes['statutory_payable'] ?? '2200', 'credit', $employer, 'SSS, PhilHealth and Pag-IBIG — employer share');
        $push($codes['withholding_payable'] ?? '2160', 'credit', $statutory['withholding_tax'], 'Withholding tax on wages');
        $push($codes['accrued_wages'] ?? '2100', 'credit', $other, 'Advances and other deductions recovered');
        $push($codes['cash'] ?? '1020', 'credit', $run->netCents(), 'Net pay');

        $debits = array_sum(array_map(static fn (array $l): int => $l['side'] === 'debit' ? $l['amount_cents'] : 0, $lines));
        $credits = array_sum(array_map(static fn (array $l): int => $l['side'] === 'credit' ? $l['amount_cents'] : 0, $lines));

        abort_if($debits !== $credits, 422, sprintf(
            '%s does not add up — its payslips total ₱%s gross against ₱%s deducted and paid. '
            .'A payslip on it has been changed outside payroll; work the figures out again.',
            $run->reference,
            number_format($debits / 100, 2),
            number_format($credits / 100, 2),
        ));

        return $this->journal->create(JournalEntryData::fromArray([
            'entry_date' => $run->pay_date?->toDateString() ?? now()->toDateString(),
            'category' => JournalCategory::Payroll->value,
            'memo' => sprintf('Payroll %s · %s', $run->reference, $run->periodLabel()),
            'status' => JournalEntry::POSTED,
            'source' => 'payroll',
            'source_type' => PayRun::class,
            'source_id' => $run->getKey(),
            'lines' => $lines,
        ]), $author);
    }

    public function remit(PayRun $run, string $on, ?string $reference = null, ?User $author = null): PayRun
    {
        abort_unless($run->isPaid(), 422, "Mark {$run->reference} paid before recording what was sent to the agencies.");
        abort_if($run->isRemitted(), 422, sprintf(
            '%s was already recorded as remitted on %s.',
            $run->reference,
            $run->remitted_on?->format('M j, Y'),
        ));

        $run->load('lines.components');

        return DB::transaction(function () use ($run, $on, $reference, $author): PayRun {
            $number = PayRun::nextRemittanceNo((int) substr($on, 0, 4));
            $entry = $this->postRemittance($run, $on, $number, $reference, $author);

            $run->forceFill([
                'remitted_on' => $on,
                'remittance_no' => $number,
                'remittance_reference' => $reference,
                'remittance_entry_id' => $entry?->getKey(),
            ])->save();

            return $this->find($run->getKey());
        });
    }

    private function postRemittance(PayRun $run, string $on, string $number, ?string $reference, ?User $author): ?JournalEntry
    {
        $codes = (array) config('cargo.payroll.accounts', []);
        $accounts = Account::query()->whereIn('code', array_values($codes))->get()->keyBy('code');

        if ($accounts->isEmpty()) {
            return null;
        }

        $statutory = $run->statutoryCents();
        $contributions = $statutory['sss'] + $statutory['philhealth'] + $statutory['pagibig']
            + $run->employerContributionsCents()['total'];
        $tax = $statutory['withholding_tax'];

        if ($contributions + $tax <= 0) {
            return null;
        }

        $lines = [];

        foreach ([
            [$codes['statutory_payable'] ?? '2200', 'debit', $contributions, 'SSS, PhilHealth and Pag-IBIG remitted'],
            [$codes['withholding_payable'] ?? '2160', 'debit', $tax, 'Withholding tax remitted to the BIR'],
            [$codes['cash'] ?? '1020', 'credit', $contributions + $tax, 'Remittance'],
        ] as [$code, $side, $amount, $memo]) {
            if ($amount <= 0) {
                continue;
            }

            $account = $accounts->get($code);

            abort_if($account === null, 422, sprintf('Account %s is missing from the chart, so this remittance cannot be posted.', $code));

            $lines[] = ['account_id' => $account->getKey(), 'side' => $side, 'amount_cents' => $amount, 'memo' => $memo];
        }

        return $this->journal->create(JournalEntryData::fromArray([
            'entry_date' => $on,
            'category' => JournalCategory::Payroll->value,
            'memo' => sprintf(
                '%s · remittance for payroll %s · %s%s',
                $number,
                $run->reference,
                $run->periodLabel(),
                $reference ? " · agency ref {$reference}" : '',
            ),
            'status' => JournalEntry::POSTED,
            'source' => 'payroll',
            'source_type' => PayRun::class,
            'source_id' => $run->getKey(),
            'source_rule' => 'remittance',
            'lines' => $lines,
        ]), $author);
    }

    public function delete(PayRun $run): void
    {
        $this->mustBeOpen($run);

        DB::transaction(function () use ($run): void {
            $run->lines()->delete();
            $run->delete();
        });
    }

    private function lockPayroll(): void
    {
        $companyId = $this->tenant->id();

        if ($companyId !== null) {
            Company::query()->whereKey($companyId)->lockForUpdate()->first();
        }
    }

    private function mustNotOverlap(Carbon $start, Carbon $end, PayrollScope $scope, ?PayRun $approving = null): void
    {
        $clash = PayRun::query()
            ->ofScope($scope)
            ->when($approving !== null, fn ($query) => $query
                ->whereKeyNot($approving->getKey())
                ->whereIn('status', [PayRun::APPROVED, PayRun::PAID]))
            ->whereDate('period_start', '<=', $end->toDateString())
            ->whereDate('period_end', '>=', $start->toDateString())
            ->orderBy('period_start')
            ->first();

        abort_if($clash !== null, 422, sprintf(
            '%s already covers %s. A period is paid on one run only — open that one%s.',
            $clash?->reference,
            $clash?->periodLabel(),
            $clash?->isDraft() ? ', or delete it first' : '',
        ));
    }

    private function mustNotRepayTrips(PayRun $run): void
    {
        $links = $run->lines->flatMap->tripLinks;

        if ($links->isEmpty()) {
            return;
        }

        $paid = PayRunLineTrip::query()
            ->settled()
            ->whereNotIn('pay_run_line_id', $run->lines->modelKeys())
            ->whereIn('trip_id', $links->pluck('trip_id')->unique()->all())
            ->get(['employee_id', 'trip_id'])
            ->filter(fn (PayRunLineTrip $settled): bool => $links->contains(
                fn (PayRunLineTrip $mine): bool => $mine->trip_id === $settled->trip_id
                    && $mine->employee_id === $settled->employee_id,
            ));

        abort_if($paid->isNotEmpty(), 422, sprintf(
            '%d trip(s) on %s have been paid on another run since it was worked out. '
            .'Work it out again before approving — that takes them off.',
            $paid->count(),
            $run->reference,
        ));
    }

    private function capStoreAtApproval(PayRun $run): void
    {
        $taking = $run->lines->filter(fn (PayRunLine $line): bool => (int) $line->store_deduction_cents > 0
            && $line->employee_id !== null);

        if ($taking->isEmpty()) {
            return;
        }

        $owed = $this->storeCredit->available(
            $taking->pluck('employee_id')->all(),
            $run->period_end->toDateString(),
            $run->getKey(),
            reservations: false,
        );

        foreach ($taking as $line) {
            $left = $owed[$line->employee_id] ?? 0;

            if ((int) $line->store_deduction_cents > $left) {
                $line->store_deduction_cents = $left;
                $this->totalsOn($line)->save();
            }
        }
    }

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
