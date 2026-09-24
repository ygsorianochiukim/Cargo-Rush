<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Hr\Models\Employee;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Models\StoreCredit;
use App\Domain\Shared\Enums\StoreCreditKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * The store tab: what people owe the mini-mart, and how a cutoff takes it off.
 *
 * ## What a cutoff takes
 *
 * The outstanding balance as at the end of the period, capped by the person's
 * own `store_deduction_cap_cents` where they have one. Zero cap — the default —
 * means the whole balance, which is what a small tab settled each payslip
 * actually does; a figure spreads a larger one without anybody having to
 * remember to stop.
 *
 * **As at the end of the period**, not as at today, and that is the difference
 * between a payslip that can be rebuilt and one that cannot. A run for the
 * first half of the month, worked out again on the 20th, must not suddenly
 * deduct the rice somebody took on the 18th — that charge belongs to the next
 * payslip, and a rebuild that moved it would change a figure the office had
 * already checked.
 *
 * ## When the repayment is written
 *
 * On **approve**, never on build. A draft exists to be worked out again, and a
 * repayment written at build time would be deducted from the balance the next
 * rebuild reads — so pressing "work out again" twice would settle the tab twice
 * and pay the person the difference. Approving is the point at which the
 * figures stop moving, which is exactly the point at which the ledger should
 * learn about them.
 *
 * That leaves one honest gap: a draft that is never approved leaves the tab
 * untouched, which is right, and a draft left open for a week shows a balance
 * that may since have grown. The balance on screen is always current; the
 * payslip's figure is always as at its period end. Both are correct answers to
 * different questions, and the payslip says which period it is for.
 */
class StoreCreditService
{
    /**
     * What one person owes, as at a date.
     *
     * @param  string|null  $asOf  `Y-m-d`; null means everything on the tab.
     */
    public function balanceFor(string $employeeId, ?string $asOf = null): int
    {
        return $this->balances([$employeeId], $asOf)[$employeeId] ?? 0;
    }

    /**
     * The same for a whole payroll, in **one** query.
     *
     * Building a run asks this once per person, and asking the database once
     * per person is how a 90-strong roster turns into ninety round trips for a
     * table it could have read in a single pass — the same reasoning
     * `TripPayService::forEmployees()` sets out.
     *
     * @param  array<int, string>  $employeeIds
     * @return array<string, int> keyed by employee id; every id present
     */
    public function balances(array $employeeIds, ?string $asOf = null): array
    {
        $balances = array_fill_keys($employeeIds, 0);

        if ($employeeIds === []) {
            return $balances;
        }

        $rows = StoreCredit::query()
            ->whereIn('employee_id', $employeeIds)
            ->when($asOf !== null, fn ($query) => $query->upTo($asOf))
            ->groupBy('employee_id', 'kind')
            ->selectRaw('employee_id, kind, SUM(amount_cents) as total')
            ->get();

        foreach ($rows as $row) {
            $kind = $row->kind instanceof StoreCreditKind
                ? $row->kind
                : StoreCreditKind::from((string) $row->kind);

            $balances[$row->employee_id] = ($balances[$row->employee_id] ?? 0)
                + $kind->sign() * (int) $row->total;
        }

        /**
         * Never below zero.
         *
         * An overpaid tab — somebody handed over more than they owed — is a
         * credit the store owes them, and it is not payroll's to hand back
         * through a negative deduction. That would be an *addition* to the
         * payslip dressed as a recovery, and nothing downstream is expecting
         * one.
         */
        return array_map(static fn (int $balance): int => max(0, $balance), $balances);
    }

    /**
     * What this period's payslip should take off the tab.
     *
     * @param  int  $balanceCents  the outstanding balance as at the period end
     */
    public function deductionFor(Employee $employee, int $balanceCents): int
    {
        $cap = (int) ($employee->store_deduction_cap_cents ?? 0);

        if ($balanceCents <= 0) {
            return 0;
        }

        return $cap > 0 ? min($cap, $balanceCents) : $balanceCents;
    }

    /**
     * Write the repayments an approved run made.
     *
     * Idempotent on the line: a run can only be approved once, but a retry
     * after a half-failed request should not settle a tab twice, so an existing
     * repayment for the line is updated rather than added to.
     *
     * @param  Collection<int, PayRunLine>|SupportCollection<int, PayRunLine>  $lines
     */
    public function settle($lines, Carbon $paidOn): void
    {
        DB::transaction(function () use ($lines, $paidOn): void {
            foreach ($lines as $line) {
                if ((int) $line->store_deduction_cents <= 0 || $line->employee_id === null) {
                    // A line that took nothing leaves no row, and a line whose
                    // employee has since been force-deleted has no tab to move.
                    StoreCredit::query()->where('pay_run_line_id', $line->getKey())->delete();

                    continue;
                }

                StoreCredit::updateOrCreate(
                    ['pay_run_line_id' => $line->getKey()],
                    [
                        'employee_id' => $line->employee_id,
                        'kind' => StoreCreditKind::Payment->value,
                        'amount_cents' => (int) $line->store_deduction_cents,
                        'description' => 'Deducted on payslip',
                        'charged_on' => $paidOn->toDateString(),
                    ],
                );
            }
        });
    }

    /**
     * Add a charge or a repayment to somebody's tab.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(Employee $employee, array $attributes, ?int $userId = null): StoreCredit
    {
        return StoreCredit::create([
            'employee_id' => $employee->getKey(),
            'kind' => $attributes['kind'] ?? StoreCreditKind::Charge->value,
            'amount_cents' => (int) ($attributes['amount_cents'] ?? 0),
            'description' => $attributes['description'] ?? null,
            'outlet' => $attributes['outlet'] ?? null,
            'charged_on' => $attributes['charged_on'] ?? now()->toDateString(),
            'notes' => $attributes['notes'] ?? null,
            'recorded_by' => $userId,
        ])->refresh();
    }

    /**
     * Remove a row from the tab.
     *
     * Refused for a repayment payroll wrote: that figure is on a payslip
     * somebody has been handed, and deleting it here would leave the tab and
     * the payslip disagreeing with nothing to say which is right. The way to
     * undo one is a correcting charge, which is what a ledger is for.
     */
    public function forget(StoreCredit $row): void
    {
        abort_if(
            $row->isFromPayroll(),
            422,
            'This came off a payslip, so it cannot be removed here. Add a correcting charge instead.',
        );

        $row->delete();
    }

    /**
     * One person's tab, newest first.
     *
     * @return Collection<int, StoreCredit>
     */
    public function ledgerFor(string $employeeId, int $limit = 100): Collection
    {
        return StoreCredit::query()
            ->forEmployee($employeeId)
            ->with('recordedBy:id,name')
            ->inLedgerOrder()
            ->limit($limit)
            ->get();
    }
}
