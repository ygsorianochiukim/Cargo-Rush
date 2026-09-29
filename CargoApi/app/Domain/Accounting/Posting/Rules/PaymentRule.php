<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Rules;

use App\Domain\Accounting\Posting\PostingRule;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Vehicle\Models\MaintenanceJob;
use Illuminate\Database\Eloquent\Model;

/**
 * Money that moved against invoices, on the day it moved (`paid_on`).
 *
 * One entry per payment rather than per allocation, because the payment is the
 * one movement of cash and the allocations are only where it went.
 *
 * **Received:** Dr 1020 the amount; Cr 1100 what was applied to each invoice;
 * Cr 2250 Customer advances for any of it not applied yet — real money, owed
 * back or owed work, until somebody applies it.
 *
 * **Paid out:** Cr 1020 the amount; Dr 2010 what was applied to each bill;
 * Dr 1200 for any of it not applied yet. And, for each bill, the expense —
 * Dr 5900 / Cr 1270 — because Finance counts a supplier bill on the day it is
 * paid (`FinanceService::supplierBillSettlements()`), at what was paid.
 *
 * **Except a bill a maintenance job carries.** Finance leaves those payments
 * out: the job's cost is already on the sheet, and `SheetDayRule` put it there
 * against 1270. Paying the bill settles 2010 and adds no expense, or one oil
 * change would be two.
 */
class PaymentRule extends PostingRule
{
    public function postings(Model $source): array
    {
        /** @var Payment $payment */
        $payment = $source;

        if ($payment->paid_on === null) {
            return [];
        }

        $direction = $payment->direction ?? InvoiceDirection::Receivable;
        $outgoing = $direction === InvoiceDirection::Payable;

        // Only allocations to a live invoice going the same way: an allocation
        // to a deleted one is, to every report, money not applied to anything.
        $allocations = PaymentAllocation::query()
            ->with('invoice:id,number,direction,customer_id,trip_id')
            ->where('payment_id', $payment->getKey())
            ->whereHas('invoice', static fn ($invoice) => $invoice->where('direction', $direction->value))
            ->get();

        $posting = $this->posting(
            'payment',
            $payment->paid_on,
            $outgoing ? JournalCategory::Disbursement : JournalCategory::Collection,
            trim(sprintf(
                '%s %s · %s',
                $outgoing ? 'Paid' : 'Received',
                $payment->reference ?? '',
                $allocations->map(static fn (PaymentAllocation $a): ?string => $a->invoice?->number)->filter()->implode(', '),
            ), ' ·'),
        )->about(['customer_id' => $payment->customer_id]);

        $amount = (int) $payment->amount_cents;
        $applied = (int) $allocations->sum('amount_cents');
        $cash = $this->code('cash');

        if (! $outgoing) {
            $posting->debit($cash, $amount, 'Received');

            foreach ($allocations as $allocation) {
                $posting->about(['customer_id' => $payment->customer_id, 'trip_id' => $allocation->invoice?->trip_id])
                    ->credit($this->code('receivable'), (int) $allocation->amount_cents, $allocation->invoice?->number);
            }

            $posting->about(['customer_id' => $payment->customer_id])
                ->credit($this->code('customer_advances'), $amount - $applied, 'Not yet applied to an invoice');

            return [$posting];
        }

        $serviced = MaintenanceJob::query()
            ->whereIn('invoice_id', $allocations->pluck('invoice_id')->all())
            ->where('posted_cents', '>', 0)
            ->pluck('invoice_id')
            ->all();

        $posting->credit($cash, $amount, 'Paid');

        foreach ($allocations as $allocation) {
            $cents = (int) $allocation->amount_cents;
            $number = $allocation->invoice?->number;

            $posting->debit($this->code('payable'), $cents, $number);

            if (! in_array($allocation->invoice_id, $serviced, true)) {
                $posting->debit($this->code('supplier_bill_expense'), $cents, $number)
                    ->credit($this->code('bills_not_expensed'), $cents, $number);
            }
        }

        $posting->debit($this->code('supplier_advances'), $amount - $applied, 'Not yet applied to a bill');

        return [$posting];
    }

    public function label(Model $source): string
    {
        /** @var Payment $source */
        return 'payment '.($source->reference ?? $source->paid_on?->toDateString() ?? $source->getKey());
    }
}
