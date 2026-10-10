<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Hr\Models\Employee;
use App\Domain\Payroll\Models\EmployeePayComponent;
use App\Domain\Payroll\Models\PayComponent;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class PayComponentService
{
    /** @return Collection<int, PayComponent> */
    public function all(bool $activeOnly = false): Collection
    {
        return PayComponent::query()
            ->when($activeOnly, fn ($query) => $query->active())
            ->inPayslipOrder()
            ->get();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): PayComponent
    {
        return PayComponent::create($attributes)->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function update(PayComponent $component, array $attributes): PayComponent
    {
        $component->update($attributes);

        return $component->refresh();
    }

    public function delete(PayComponent $component): bool
    {
        $assigned = EmployeePayComponent::query()
            ->where('pay_component_id', $component->getKey())
            ->exists();

        if ($assigned) {
            $component->update(['status' => StatusValue::Inactive->value]);

            return false;
        }

        $component->delete();

        return true;
    }

    /** @return Collection<int, EmployeePayComponent> */
    public function assignmentsFor(Employee $employee): Collection
    {
        return EmployeePayComponent::query()
            ->with('component')
            ->where('employee_id', $employee->getKey())
            ->orderByDesc('effective_from')
            ->get();
    }

    /** @param array<string, mixed> $attributes */
    public function assign(array $attributes): EmployeePayComponent
    {
        return EmployeePayComponent::create($attributes)->refresh()->load('component');
    }

    /** @param array<string, mixed> $attributes */
    public function updateAssignment(EmployeePayComponent $assignment, array $attributes): EmployeePayComponent
    {
        $assignment->update($attributes);

        return $assignment->refresh()->load('component');
    }

    public function unassign(EmployeePayComponent $assignment): void
    {
        $assignment->delete();
    }

    /**
     * @param  array{first: bool, only: bool, index: int, count: int}  $cutoff
     * @return array<int, array{component: PayComponent, name: string, kind: string, taxable: bool, amount_cents: int, monthly_cents: int}>
     */
    public function resolve(
        Employee $employee,
        int $monthlyBasicCents,
        Carbon $periodStart,
        Carbon $periodEnd,
        array $cutoff,
    ): array {
        $assignments = EmployeePayComponent::query()
            ->with('component')
            ->where('employee_id', $employee->getKey())
            ->active()
            ->coveringPeriod($periodStart, $periodEnd)
            ->get();

        return $this->fromAssignments($assignments, $monthlyBasicCents, $cutoff);
    }

    /**
     * @param  iterable<Employee>  $employees
     * @param  array<string, int>  $monthlyBasics
     * @param  array{first: bool, only: bool, index: int, count: int}  $cutoff
     * @return array<string, array<int, array{component: PayComponent, name: string, kind: string, taxable: bool, amount_cents: int}>>
     */
    public function resolveFor(
        iterable $employees,
        array $monthlyBasics,
        Carbon $periodStart,
        Carbon $periodEnd,
        array $cutoff,
    ): array {
        $ids = [];
        $resolved = [];

        foreach ($employees as $employee) {
            $ids[] = $employee->getKey();
            $resolved[$employee->getKey()] = [];
        }

        if ($ids === []) {
            return $resolved;
        }

        $assignments = EmployeePayComponent::query()
            ->with('component')
            ->whereIn('employee_id', $ids)
            ->active()
            ->coveringPeriod($periodStart, $periodEnd)
            ->get()
            ->groupBy('employee_id');

        foreach ($resolved as $employeeId => $_) {
            $resolved[$employeeId] = $this->fromAssignments(
                $assignments->get($employeeId) ?? collect(),
                $monthlyBasics[$employeeId] ?? 0,
                $cutoff,
            );
        }

        return $resolved;
    }

    /**
     * @param  iterable<EmployeePayComponent>  $assignments
     * @param  array{first: bool, only: bool, index: int, count: int}  $cutoff
     * @return array<int, array{component: PayComponent, name: string, kind: string, taxable: bool, amount_cents: int, monthly_cents: int}>
     */
    private function fromAssignments(iterable $assignments, int $monthlyBasicCents, array $cutoff): array
    {
        $resolved = [];

        foreach ($assignments as $assignment) {
            $component = $assignment->component;

            if ($component === null || ! $component->isActive()) {
                continue;
            }

            $monthly = $component->monthlyCentsFor(
                $monthlyBasicCents,
                $assignment->amount_cents,
                $assignment->rate_bp,
            );

            $amount = $component->schedule->shareOf($monthly, $cutoff['index'], $cutoff['count']);

            if ($amount <= 0) {
                continue;
            }

            $resolved[] = [
                'component' => $component,
                'name' => (string) $component->name,
                'kind' => $component->kind->value,
                'taxable' => $component->isTaxable(),
                'amount_cents' => $amount,
                'monthly_cents' => $monthly,
            ];
        }

        usort($resolved, static function (array $a, array $b): int {
            return [$a['component']->position, $a['name']] <=> [$b['component']->position, $b['name']];
        });

        return $resolved;
    }
}
