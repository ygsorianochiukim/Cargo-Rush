<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Repositories\ExpenseRepository;
use App\Domain\Finance\Repositories\LedgerRepository;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Fuel\Repositories\FuelRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ExpenseLinesService
{
    private const COLUMNS = [
        'fuel_cents' => 'Fuel',
        'driver_salary_cents' => 'Driver salary',
        'helper_salary_cents' => 'Helper salary',
        'maintenance_cents' => 'Maintenance',
        'allowance_cents' => 'Allowance',
        'owner_share_cents' => "Owner's share",
    ];

    public function __construct(
        private readonly LedgerRepository $ledger,
        private readonly ExpenseRepository $expenses,
        private readonly FuelRepository $fuel,
        private readonly FinanceService $finance,
        private readonly PayrollCostService $payroll,
    ) {}

    /** @return array{range: array{from: string, to: string}, lines: array<int, array<string, mixed>>, total_cents: int, currency: string} */
    public function between(Carbon $from, Carbon $to): array
    {
        $trucks = $this->ledger->trucks()->keyBy('id');

        $posted = $this->fuel->postedBetween($from, $to)
            ->filter(static fn (FuelRecord $fill): bool => $trucks->has($fill->posted_truck_id))
            ->values();

        $lines = [
            ...$this->sheetLines($from, $to, $trucks, $posted),
            ...$this->expenseLines($from, $to, $trucks),
            ...$this->fuelLogLines($from, $to, $trucks, $posted),
            ...$this->supplierBillLines($from, $to),
            ...$this->payrollLines($from, $to),
        ];

        usort($lines, static fn (array $a, array $b): int => [$b['date'], $a['key']] <=> [$a['date'], $b['key']]);

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'lines' => $lines,
            'total_cents' => (int) array_sum(array_column($lines, 'amount_cents')),
            'currency' => 'PHP',
        ];
    }

    private function sheetLines(Carbon $from, Carbon $to, Collection $trucks, Collection $posted): array
    {
        $lines = [];

        $entries = $this->ledger->entriesBetween($from, $to)
            ->filter(static fn (LedgerEntry $entry): bool => $trucks->has($entry->truck_id))
            ->load('helpers.driver:id,name');

        $fromFills = $posted->groupBy(static fn (FuelRecord $fill): string => $fill->posted_truck_id.'|'.$fill->posted_on->toDateString())
            ->map(static fn (Collection $fills): int => (int) $fills->sum('posted_cents'))
            ->all();

        foreach ($entries as $entry) {
            $truck = $trucks->get($entry->truck_id);

            foreach (self::COLUMNS as $column => $label) {
                $cents = (int) $entry->{$column};

                if ($column === 'fuel_cents') {
                    $day = $entry->truck_id.'|'.$entry->date->toDateString();
                    $taken = min($cents, $fromFills[$day] ?? 0);
                    $fromFills[$day] = ($fromFills[$day] ?? 0) - $taken;
                    $cents -= $taken;
                }

                if ($cents === 0) {
                    continue;
                }

                if ($column === 'helper_salary_cents' && $entry->helpers->isNotEmpty()) {
                    foreach ($entry->helpers as $line) {
                        if ($line->salary_cents === 0) {
                            continue;
                        }

                        $lines[] = $this->line(
                            key: "sheet:{$entry->id}:helper:{$line->id}",
                            source: 'sheet',
                            date: $entry->date->toDateString(),
                            kind: $label,
                            description: $line->driver?->name ?? $entry->route ?? $entry->remarks,
                            truck: $truck->plate ?? $truck->label,
                            cents: (int) $line->salary_cents,
                            recordId: $entry->id,
                        );
                    }

                    continue;
                }

                $lines[] = $this->line(
                    key: "sheet:{$entry->id}:{$column}",
                    source: 'sheet',
                    date: $entry->date->toDateString(),
                    kind: $label,
                    description: $entry->route ?? $entry->remarks,
                    truck: $truck->plate ?? $truck->label,
                    cents: $cents,
                    recordId: $entry->id,
                );
            }
        }

        return $lines;
    }

    private function expenseLines(Carbon $from, Carbon $to, Collection $trucks): array
    {
        return $this->expenses->between($from, $to)
            ->filter(static fn (Expense $e): bool => $e->truck_id === null || $trucks->has($e->truck_id))
            ->load(['category:id,name', 'supplier:id,name'])
            ->map(function (Expense $expense) use ($trucks): array {
                $truck = $expense->truck_id === null ? null : $trucks->get($expense->truck_id);

                return $this->line(
                    key: "expense:{$expense->id}",
                    source: 'expense',
                    date: $expense->date->toDateString(),
                    kind: $expense->category?->name ?? 'Expense',
                    description: $expense->supplier?->name ?? $expense->payee ?? $expense->note,
                    truck: $truck === null ? null : ($truck->plate ?? $truck->label),
                    cents: (int) $expense->amount_cents,
                    recordId: $expense->id,
                );
            })
            ->values()
            ->all();
    }

    private function fuelLogLines(Carbon $from, Carbon $to, Collection $trucks, Collection $posted): array
    {
        $truckOf = $trucks->whereNotNull('vehicle_id')->pluck('id', 'vehicle_id');

        $unposted = $this->fuel->unpostedBetween($from, $to);

        return (new EloquentCollection([...$posted->all(), ...$unposted->all()]))
            ->load(['vehicle:id,plate', 'driver:id,name'])
            ->map(function (FuelRecord $fill) use ($trucks, $truckOf): array {
                $truck = $fill->isPosted()
                    ? $trucks->get($fill->posted_truck_id)
                    : $trucks->get($truckOf[$fill->vehicle_id] ?? '');

                return $this->line(
                    key: "fuel:{$fill->id}",
                    source: 'fuel_log',
                    date: ($fill->isPosted() ? $fill->posted_on : $fill->logged_at)->toDateString(),
                    kind: 'Fuel',
                    description: trim($fill->receipt_no.' · '.($fill->driver?->name ?? ''), ' ·'),
                    truck: $truck === null ? $fill->vehicle?->plate : ($truck->plate ?? $truck->label),
                    cents: $fill->isPosted() ? (int) $fill->posted_cents : (int) $fill->amount_cents,
                    recordId: $fill->id,
                );
            })
            ->values()
            ->all();
    }

    private function payrollLines(Carbon $from, Carbon $to): array
    {
        $lines = [];

        foreach ($this->payroll->paidBetween($from, $to) as $run) {
            $label = $run['run']->reference.' · '.$run['run']->periodLabel();

            if ($run['wages_cents'] !== 0) {
                $lines[] = $this->line(
                    key: "payroll:{$run['run']->id}",
                    source: 'payroll',
                    date: $run['date'],
                    kind: 'Payroll',
                    description: trim($label
                        .($run['sheet_cents'] > 0 ? ' · less crew pay already on the sheet' : ''), ' ·'),
                    truck: null,
                    cents: $run['wages_cents'],
                    recordId: $run['run']->id,
                );
            }

            if ($run['employer_cents'] !== 0) {
                $lines[] = $this->line(
                    key: "payroll:{$run['run']->id}:employer",
                    source: 'payroll',
                    date: $run['date'],
                    kind: 'Employer contributions',
                    description: trim($label.' · SSS, EC, PhilHealth and Pag-IBIG, employer share', ' ·'),
                    truck: null,
                    cents: $run['employer_cents'],
                    recordId: $run['run']->id,
                );
            }
        }

        return $lines;
    }

    private function supplierBillLines(Carbon $from, Carbon $to): array
    {
        return $this->finance->supplierBillSettlements($from, $to)
            ->load('invoice')
            ->map(fn (PaymentAllocation $allocation): array => $this->line(
                key: "bill:{$allocation->id}",
                source: 'supplier_bill',
                date: $allocation->payment->paid_on->toDateString(),
                kind: 'Supplier bill',
                description: trim(($allocation->invoice?->number ?? '').' · '.($allocation->invoice?->counterparty() ?? ''), ' ·'),
                truck: null,
                cents: (int) $allocation->amount_cents,
                recordId: $allocation->invoice_id,
            ))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function line(
        string $key,
        string $source,
        string $date,
        string $kind,
        ?string $description,
        ?string $truck,
        int $cents,
        ?string $recordId,
    ): array {
        return [
            'key' => $key,
            'source' => $source,
            'date' => $date,
            'kind' => $kind,
            'description' => $description === '' ? null : $description,
            'truck' => $truck,
            'amount_cents' => $cents,
            'record_id' => $recordId,
        ];
    }
}
