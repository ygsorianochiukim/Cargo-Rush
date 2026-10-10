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
