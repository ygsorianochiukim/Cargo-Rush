<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Hr\Models\Employee;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Models\StoreCredit;
use App\Domain\Shared\Enums\StoreCreditKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class StoreCreditService
{
    public function balanceFor(string $employeeId, ?string $asOf = null): int
    {
        return $this->balances([$employeeId], $asOf)[$employeeId] ?? 0;
    }

    /**
     * @param  array<int, string>  $employeeIds
     * @return array<string, int>
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

        return array_map(static fn (int $balance): int => max(0, $balance), $balances);
    }

    /**
     * @param  array<int, string>  $employeeIds
     * @return array<string, int>
     */
    public function available(array $employeeIds, string $asOf, ?string $runId = null, bool $reservations = true): array
    {
        $available = array_fill_keys($employeeIds, 0);

        if ($employeeIds === []) {
            return $available;
        }

        $ownLines = $runId === null
            ? []
            : PayRunLine::query()->where('pay_run_id', $runId)->pluck('id')->all();

        $rows = StoreCredit::query()
            ->whereIn('employee_id', $employeeIds)
            ->where(fn ($query) => $query
                ->where('kind', StoreCreditKind::Payment->value)
                ->orWhereDate('charged_on', '<=', $asOf))
            ->when($ownLines !== [], fn ($query) => $query->where(fn ($inner) => $inner
                ->whereNull('pay_run_line_id')
                ->orWhereNotIn('pay_run_line_id', $ownLines)))
            ->groupBy('employee_id', 'kind')
            ->selectRaw('employee_id, kind, SUM(amount_cents) as total')
            ->get();

        foreach ($rows as $row) {
            $kind = $row->kind instanceof StoreCreditKind
                ? $row->kind
                : StoreCreditKind::from((string) $row->kind);

            $available[$row->employee_id] += $kind->sign() * (int) $row->total;
        }

        if ($reservations) {
            $reserved = PayRunLine::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('store_deduction_cents', '>', 0)
                ->whereHas('payRun', fn ($run) => $run
                    ->where('status', PayRun::DRAFT)
                    ->when($runId !== null, fn ($query) => $query->whereKeyNot($runId)))
                ->groupBy('employee_id')
                ->selectRaw('employee_id, SUM(store_deduction_cents) as total')
                ->pluck('total', 'employee_id');

            foreach ($reserved as $employeeId => $total) {
                $available[$employeeId] -= (int) $total;
            }
        }

        return array_map(static fn (int $cents): int => max(0, $cents), $available);
    }

    public function deductionFor(Employee $employee, int $balanceCents): int
    {
        return max(0, $balanceCents);
    }

    /** @param Collection<int, PayRunLine>|SupportCollection<int, PayRunLine> $lines */
    public function settle($lines, Carbon $paidOn): void
    {
        DB::transaction(function () use ($lines, $paidOn): void {
            foreach ($lines as $line) {
                if ((int) $line->store_deduction_cents <= 0 || $line->employee_id === null) {
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

    /** @param array<string, mixed> $attributes */
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

    public function forget(StoreCredit $row): void
    {
        abort_if(
            $row->isFromPayroll(),
            422,
            'This came off a payslip, so it cannot be removed here. Add a correcting charge instead.',
        );

        $row->delete();
    }

    /** @return Collection<int, StoreCredit> */
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
