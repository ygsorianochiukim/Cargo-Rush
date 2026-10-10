<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\WalletEntry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class ReceivablesService
{
    public function outstandingAsOf(CarbonInterface $asOf): int
    {
        return (int) array_sum(array_column($this->linesAsOf($asOf), 'amount_cents'));
    }

    /** @return array<int, array<string, mixed>> */
    public function linesAsOf(CarbonInterface $asOf): array
    {
        $lines = [...$this->invoiceLines($asOf), ...$this->truckerLines($asOf)];

        usort($lines, static fn (array $a, array $b): int => [$a['due_at'] === null, $a['due_at'], $a['key']]
            <=> [$b['due_at'] === null, $b['due_at'], $b['key']]);

        return $lines;
    }

    private function invoiceLines(CarbonInterface $asOf): array
    {
        $date = $asOf->toDateString();

        return Invoice::query()
            ->with('customer:id,name')
            ->where('direction', InvoiceDirection::Receivable->value)
            ->where('status', '!=', StatusValue::Cancelled->value)
            ->whereDate('issued_at', '<=', $date)
            ->withSum(
                ['allocations as paid_by_then' => static fn (Builder $query) => $query
                    ->whereHas('payment', static fn (Builder $payment) => $payment
                        ->whereDate('paid_on', '<=', $date))],
                'amount_cents',
            )
            ->get()
            ->map(static function (Invoice $invoice) use ($asOf): array {
                $balance = max(0, $invoice->dueCents() - (int) ($invoice->paid_by_then ?? 0));

                return [
                    'key' => "invoice:{$invoice->id}",
                    'source' => 'invoice',
                    'reference' => $invoice->number,
                    'counterparty' => $invoice->counterparty(),
                    'issued_at' => $invoice->issued_at?->toDateString(),
                    'due_at' => $invoice->due_at?->toDateString(),
                    'overdue' => $invoice->due_at !== null && $invoice->due_at->lt($asOf->copy()->startOfDay()),
                    'amount_cents' => $balance,
                    'record_id' => $invoice->id,
                ];
            })
            ->filter(static fn (array $line): bool => $line['amount_cents'] > 0)
            ->values()
            ->all();
    }

    private function truckerLines(CarbonInterface $asOf): array
    {
        $owing = WalletEntry::query()
            ->landed()
            ->whereDate('occurred_on', '<=', $asOf->toDateString())
            ->selectRaw('trucker_id, sum(amount_cents) as balance')
            ->groupBy('trucker_id')
            ->pluck('balance', 'trucker_id')
            ->filter(static fn ($balance): bool => (int) $balance < 0);

        if ($owing->isEmpty()) {
            return [];
        }

        $names = Trucker::query()->whereIn('id', $owing->keys())->pluck('name', 'id');

        return $owing
            ->map(static fn ($balance, string $truckerId): array => [
                'key' => "trucker:{$truckerId}",
                'source' => 'trucker',
                'reference' => null,
                'counterparty' => $names->get($truckerId) ?? 'Partner trucker',
                'issued_at' => null,
                'due_at' => null,
                'overdue' => false,
                'amount_cents' => abs((int) $balance),
                'record_id' => $truckerId,
            ])
            ->values()
            ->all();
    }
}
