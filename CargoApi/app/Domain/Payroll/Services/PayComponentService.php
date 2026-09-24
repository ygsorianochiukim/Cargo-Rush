<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Hr\Models\Employee;
use App\Domain\Payroll\Models\EmployeePayComponent;
use App\Domain\Payroll\Models\PayComponent;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The firm's salary structure: what it pays and deducts, and who gets it.
 *
 * Two jobs, and the second is the one payroll actually calls.
 *
 * **Keeping the catalogue** — ordinary CRUD over `pay_components`, with one
 * rule worth naming: a component in use is retired rather than deleted where
 * the office asks for a delete, because deleting it would strand the
 * assignments pointing at it. See `delete()`.
 *
 * **Resolving a payslip** — given an employee, their monthly basic and the
 * period being paid, work out every component that applies and what each is
 * worth on *this* payslip. That is three questions stacked, and keeping them
 * separate is what makes the answer checkable:
 *
 *   1. Does the assignment's date window overlap the period?
 *   2. What is the monthly figure — the catalogue's, the assignment's override,
 *      or a percentage of the basic?
 *   3. How much of that monthly figure does this cutoff carry?
 *
 * Only the third knows about cutoffs, and it is `PayComponentSchedule`, which
 * hands the splitting rule to `DeductionSchedule` rather than writing a second
 * copy of it.
 */
class PayComponentService
{
    /**
     * The catalogue.
     *
     * @return Collection<int, PayComponent>
     */
    public function all(bool $activeOnly = false): Collection
    {
        return PayComponent::query()
            ->when($activeOnly, fn ($query) => $query->active())
            ->inPayslipOrder()
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PayComponent
    {
        return PayComponent::create($attributes)->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(PayComponent $component, array $attributes): PayComponent
    {
        $component->update($attributes);

        return $component->refresh();
    }

    /**
     * Delete a component, or retire it if anybody is assigned it.
     *
     * The office asked to remove it either way, and both answers honour that —
     * it stops appearing on future payslips. What differs is the wreckage:
     * deleting one with live assignments would cascade them away, so a firm
     * that removed a COLA by mistake could not put it back by re-adding it,
     * because the ninety people who had it would be gone with it.
     *
     * Retiring is reversible and loses nothing. Deleting an unused one is a
     * genuine tidy-up and is allowed.
     *
     * Either way, payslips that already carried it are untouched: they hold
     * their own frozen copy — see `PayRunLineComponent`.
     *
     * @return bool True where the row was deleted, false where it was retired.
     */
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

    /**
     * Who gets what.
     *
     * @return Collection<int, EmployeePayComponent>
     */
    public function assignmentsFor(Employee $employee): Collection
    {
        return EmployeePayComponent::query()
            ->with('component')
            ->where('employee_id', $employee->getKey())
            ->orderByDesc('effective_from')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function assign(array $attributes): EmployeePayComponent
    {
        return EmployeePayComponent::create($attributes)->refresh()->load('component');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateAssignment(EmployeePayComponent $assignment, array $attributes): EmployeePayComponent
    {
        $assignment->update($attributes);

        return $assignment->refresh()->load('component');
    }

    /**
     * End an assignment.
     *
     * Deleted outright, unlike a component: an assignment is an instruction
     * about future payslips and nothing points at it. A firm that wants the
     * history keeps it by setting `effective_to` instead, which is the better
     * answer and the one the form should offer — but "remove this line" has to
     * mean something when somebody was assigned the wrong allowance this
     * morning.
     */
    public function unassign(EmployeePayComponent $assignment): void
    {
        $assignment->delete();
    }

    /**
     * Everything that lands on one person's payslip for one period.
     *
     * Zero amounts are dropped rather than listed. A component loaded onto the
     * other cutoff is genuinely not on this payslip, and a payslip carrying
     * "Rice allowance ₱0.00" every other fortnight is noise an employee would
     * reasonably ask about. What components exist is a question for the
     * catalogue screen, not for a payslip.
     *
     * @param  array{first: bool, only: bool}  $cutoff
     * @return array<int, array{component: PayComponent, name: string, kind: string, taxable: bool, amount_cents: int}>
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
     * The same question for a whole payroll, in **one** query.
     *
     * Building a run asks this once per person; asking the database once per
     * person is how a 90-strong roster turns into ninety round trips for a
     * table it could have read in a single pass. The per-employee method above
     * is kept for a caller with one person in hand and shares the arithmetic
     * below, so there is one implementation rather than two that can drift.
     *
     * The monthly basic differs per employee — a per-trip driver's is inferred
     * from what they actually earned — so it arrives as a map rather than a
     * figure.
     *
     * @param  iterable<Employee>  $employees
     * @param  array<string, int>  $monthlyBasics  employee id => monthly basic
     * @param  array{first: bool, only: bool}  $cutoff
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
     * Turn a person's assignments into the lines their payslip carries.
     *
     * The arithmetic, with no query in it — which is what lets the batched and
     * single-employee paths above share it.
     *
     * @param  iterable<EmployeePayComponent>  $assignments
     * @param  array{first: bool, only: bool}  $cutoff
     * @return array<int, array{component: PayComponent, name: string, kind: string, taxable: bool, amount_cents: int}>
     */
    private function fromAssignments(iterable $assignments, int $monthlyBasicCents, array $cutoff): array
    {
        $resolved = [];

        foreach ($assignments as $assignment) {
            $component = $assignment->component;

            // A component that has been retired stops appearing on new
            // payslips, which is what retiring it is for. Null is the belt to
            // that brace — the relation cascades, so it should not happen.
            if ($component === null || ! $component->isActive()) {
                continue;
            }

            $monthly = $component->monthlyCentsFor(
                $monthlyBasicCents,
                $assignment->amount_cents,
                $assignment->rate_bp,
            );

            $amount = $component->schedule->shareOf($monthly, $cutoff['first'], $cutoff['only']);

            if ($amount <= 0) {
                continue;
            }

            $resolved[] = [
                'component' => $component,
                'name' => (string) $component->name,
                'kind' => $component->kind->value,
                'taxable' => $component->isTaxable(),
                'amount_cents' => $amount,
            ];
        }

        // The office's order, so a payslip reads the same way every fortnight.
        usort($resolved, static function (array $a, array $b): int {
            return [$a['component']->position, $a['name']] <=> [$b['component']->position, $b['name']];
        });

        return $resolved;
    }
}
