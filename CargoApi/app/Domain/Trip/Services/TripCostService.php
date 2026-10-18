<?php

declare(strict_types=1);

namespace App\Domain\Trip\Services;

use App\Domain\Billing\Services\TaxService;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\WalletEntry;

class TripCostService
{
    public const RELATIONS = [
        'customer:id,name,vat_treatment',
        'deliveryLog:id,trip_id,fuel_cost_cents,posted_toll_expense_id,payment_source_id',
        'deliveryLog.paymentSource:id,name,kind,last_four',
        'expenses.category:id,name',
        'expenses.paymentSource:id,name,kind,last_four',
        'walletEntries:id,trip_id,kind,amount_cents',
    ];

    public function __construct(private readonly TaxService $tax) {}

    /** @return array<string, mixed> */
    public function for(Trip $trip): array
    {
        $trip->loadMissing(self::RELATIONS);

        $partner = $trip->hauledByPartner();
        $lines = $partner ? [] : $this->tripLines($trip);
        $tollExpenseId = $trip->deliveryLog?->posted_toll_expense_id;

        foreach ($trip->expenses as $expense) {
            if ($expense->status !== StatusValue::Active) {
                continue;
            }

            $lines[] = $this->line(
                key: "expense:{$expense->id}",
                kind: $expense->getKey() === $tollExpenseId ? 'Toll' : ($expense->category?->name ?? 'Expense'),
                description: $expense->payee ?? $expense->note,
                cents: (int) $expense->amount_cents,
                source: 'expense',
                recordId: $expense->id,
                paidWith: $expense->paymentSource?->label(),
            );
        }

        $income = $this->income($trip);
        $costs = (int) array_sum(array_column($lines, 'amount_cents'));

        return [
            'trip_id' => $trip->id,
            'hauled_by' => $partner ? 'trucker' : 'company',
            'income_label' => $partner ? 'Our commission' : 'Trip income (net of VAT)',
            'income_cents' => $income,
            'lines' => $lines,
            'costs_cents' => $costs,
            'net_cents' => $income - $costs,
            'final' => $trip->billed_at !== null,
            'currency' => $trip->currency ?? 'PHP',
        ];
    }

    private function income(Trip $trip): int
    {
        if ($trip->status === StatusValue::Cancelled) {
            return 0;
        }

        if ($trip->hauledByPartner()) {
            return (int) ($trip->commission_cents ?? 0);
        }

        if ($trip->price_cents === null) {
            return 0;
        }

        return $this->tax->on((int) $trip->price_cents, $trip->customer)->net_cents;
    }

    /** @return array<int, array<string, mixed>> */
    private function tripLines(Trip $trip): array
    {
        $log = $trip->deliveryLog;

        $lines = [];

        $fixed = [
            'fuel' => ['Fuel', (int) ($log?->fuel_cost_cents ?? 0)],
            'driver_allowance' => ['Driver allowance', (int) $trip->driver_allowance_cents],
            'helper_allowance' => ['Helper allowance', (int) $trip->helper_allowance_cents],
            'toll' => ['Toll', $log?->posted_toll_expense_id === null ? (int) $trip->toll_cents : 0],
            'owner_share' => ["Owner's share", (int) $trip->walletEntries
                ->filter(static fn (WalletEntry $entry): bool => $entry->kind === WalletEntryKind::Earning)
                ->sum(static fn (WalletEntry $entry): int => abs((int) $entry->amount_cents))],
        ];

        foreach ($fixed as $key => [$kind, $cents]) {
            if ($cents > 0) {
                $lines[] = $this->line(
                    key: "trip:{$key}",
                    kind: $kind,
                    description: null,
                    cents: $cents,
                    source: $key === 'fuel' ? 'delivery_log' : 'trip',
                    recordId: $trip->id,
                    paidWith: $key === 'fuel' ? $log?->paymentSource?->label() : null,
                );
            }
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    private function line(
        string $key,
        string $kind,
        ?string $description,
        int $cents,
        string $source,
        ?string $recordId,
        ?string $paidWith = null,
    ): array {
        return [
            'key' => $key,
            'kind' => $kind,
            'description' => $description,
            'amount_cents' => $cents,
            'source' => $source,
            'record_id' => $recordId,
            'paid_with' => $paidWith,
        ];
    }
}
