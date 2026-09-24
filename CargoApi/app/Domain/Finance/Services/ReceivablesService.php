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

/**
 * What is owed **to** the fleet, as at a date — the mirror of Payables.
 *
 * Two kinds of debt, each wound back to the date the way
 * `PayablesService::outstandingAsOf()` winds back its own:
 *
 *   a customer's invoice, from what is due on it less what had been paid
 *   against it by then;
 *   a partner whose wallet had gone into the red by then — the fleet's cut of
 *   a run they billed themselves, not yet remitted. Payables leaves those out
 *   on purpose and says they belong here.
 *
 * "Due" is the face value less the withholding the customer keeps back and
 * remits to the BIR on the fleet's behalf. That money is never coming from the
 * customer, and counting it made a half-paid invoice look entirely unpaid.
 *
 * Not an income figure. The trip income behind an invoice reached the ledger
 * when the run was delivered, so it is already in Net income; this is how much
 * of that has not yet turned into money, and nothing is added to or taken off
 * any other figure because of it.
 */
class ReceivablesService
{
    public function outstandingAsOf(CarbonInterface $asOf): int
    {
        return (int) array_sum(array_column($this->linesAsOf($asOf), 'amount_cents'));
    }

    /**
     * Each debt as a row, most overdue first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function linesAsOf(CarbonInterface $asOf): array
    {
        $lines = [...$this->invoiceLines($asOf), ...$this->truckerLines($asOf)];

        // The oldest due date first — the order somebody chasing money works
        // in. Partners carry no due date and go last.
        usort($lines, static fn (array $a, array $b): int => [$a['due_at'] === null, $a['due_at'], $a['key']]
            <=> [$b['due_at'] === null, $b['due_at'], $b['key']]);

        return $lines;
    }

    /** Customer invoices raised by the date, less what had been paid on them by it. */
    private function invoiceLines(CarbonInterface $asOf): array
    {
        $date = $asOf->toDateString();

        return Invoice::query()
            ->with('customer:id,name')
            ->where('direction', InvoiceDirection::Receivable->value)
            // Paid ones stay in: one settled in October was still owed at the
            // close of September. Only a cancelled invoice was never owed.
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
                // Floored, so an overpaid invoice does not lend its credit to
                // the next one's balance.
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

    /** Partners whose wallet was in the red by the date, counting only what had landed. */
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
