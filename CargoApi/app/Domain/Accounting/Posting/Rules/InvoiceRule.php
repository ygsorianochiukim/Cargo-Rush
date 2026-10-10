<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Rules;

use App\Domain\Accounting\Posting\Posting;
use App\Domain\Accounting\Posting\PostingRule;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Finance\Services\FinanceService;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Enums\StatusValue;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class InvoiceRule extends PostingRule
{
    public function postings(Model $source): array
    {
        /** @var Invoice $invoice */
        $invoice = $source;

        if ($invoice->status === StatusValue::Cancelled) {
            return [];
        }

        $issued = $invoice->issued_at ?? $invoice->created_at;

        if ($issued === null) {
            return [];
        }

        return $invoice->direction === InvoiceDirection::Payable
            ? $this->bill($invoice, $issued)
            : [$this->receivable($invoice, $issued)];
    }

    public function label(Model $source): string
    {
        /** @var Invoice $source */
        return ($source->direction === InvoiceDirection::Payable ? 'bill ' : 'invoice ').$source->number;
    }

    private function receivable(Invoice $invoice, CarbonInterface $issued): Posting
    {
        $gross = (int) $invoice->amount_cents;
        $net = FinanceService::invoiceNetCents($invoice);
        $vat = (int) $invoice->vat_cents;
        $withheld = (int) $invoice->withholding_cents;
        $unbilled = $this->code('unbilled_income');

        $posting = $this->posting(
            'issue',
            $issued,
            JournalCategory::Billing,
            sprintf('Invoice %s · %s', $invoice->number, $invoice->counterparty()),
        )->about(['customer_id' => $invoice->customer_id, 'trip_id' => $invoice->trip_id]);

        $posting->debit($this->code('receivable'), $gross, 'Invoiced')
            ->credit($invoice->trip_id !== null ? $unbilled : $this->code('other_income'), $net, 'Net of VAT')
            ->credit($this->code('output_vat'), $vat, 'Output VAT')
            ->credit($unbilled, $gross - $net - $vat, 'Rounding on the invoice')
            ->debit($this->code('creditable_withholding'), $withheld, 'Withheld by the customer')
            ->credit($this->code('receivable'), $withheld, 'Withheld by the customer');

        return $posting;
    }

    /** @return Posting[] */
    private function bill(Invoice $invoice, CarbonInterface $issued): array
    {
        $amount = (int) $invoice->amount_cents;
        $clearing = $this->code('bills_not_expensed');
        $payable = $this->code('payable');

        $postings = [
            $this->posting(
                'issue',
                $issued,
                JournalCategory::Billing,
                sprintf('Bill %s · %s', $invoice->number, $invoice->counterparty()),
            )->debit($clearing, $amount, 'Owed to the supplier')
                ->credit($payable, $amount, 'Owed to the supplier'),
        ];

        if ($invoice->status === StatusValue::Paid) {
            $paid = (int) PaymentAllocation::query()
                ->where('invoice_id', $invoice->getKey())
                ->whereHas('payment')
                ->sum('amount_cents');

            $postings[] = $this->posting(
                'marked-paid',
                $invoice->paid_at ?? $issued,
                JournalCategory::Disbursement,
                sprintf('Bill %s marked paid with no payment recorded', $invoice->number),
            )->debit($payable, max(0, $amount - $paid), 'Marked paid')
                ->credit($clearing, max(0, $amount - $paid), 'Marked paid');
        }

        return $postings;
    }
}
