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

/**
 * An invoice, either way, on the day it was issued. A cancelled one charged
 * nobody anything and posts nothing.
 *
 * ## Receivable: the customer now owes it
 *
 *     Dr 1100 Accounts receivable     the gross (net + VAT)
 *       Cr 1140 Unbilled trip income    the net, for a trip's invoice
 *       Cr 4090 Other income            the net, for one raised by hand
 *       Cr 2150 Output VAT              the VAT — the government's, never income
 *     Dr 1260 Creditable withholding  the EWT the customer keeps back
 *       Cr 1100                         (on the net; frozen on the invoice)
 *
 * A trip's invoice does not credit revenue: the run was income the day it was
 * delivered, on the daily sheet or the partner's wallet row, and this clears
 * that income into what the customer owes. A partner's share inside it is the
 * partner's — it reached 2020 from their wallet row, not from here. A manual
 * invoice has no run behind it, so its net is income here, on the day issued,
 * exactly as `FinanceService::otherIncomeCents()` counts it.
 *
 * The withholding comes off at issue because the invoice already says what it
 * is (`withholding_cents`) and Billing's balance is `amount − withholding −
 * paid` from the start. Posting it here is what makes 1100 equal the
 * outstanding figure on the Billing page at every date.
 *
 * ## Payable: a supplier's bill
 *
 *     Dr 1270 Supplier bills not yet expensed / Cr 2010 Accounts payable
 *
 * Owed from the day it is issued — the Payables page lists it — but not yet an
 * expense: Finance counts a bill on the day it is **paid**, and a garage bill a
 * maintenance job carries not at all (the job's cost is on the sheet). The
 * payment moves it to an expense (`PaymentRule`); the job clears it
 * (`SheetDayRule`).
 *
 * A bill marked paid with no payment behind it is, to the Payables page, no
 * longer owed. The remainder is taken off 2010 on that day against the same
 * clearing account — no expense, because Finance counted none.
 */
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
            // A gross that is not quite net plus VAT — an old document, a
            // rounding. Parked on the clearing account where it can be seen,
            // rather than bent into the income or the tax.
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
