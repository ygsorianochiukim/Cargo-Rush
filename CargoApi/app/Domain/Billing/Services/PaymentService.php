<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recording money, and putting it against documents.
 *
 * The two halves are separate on purpose. A payment is an event — a cheque
 * cleared on the 14th, reference 88213 — and where it was applied is a
 * decision that may span four invoices or half of one. Modelling it as a
 * status on the invoice, which is what this replaces, could express neither.
 *
 * **Invoice statuses are written from here and nowhere else.** `paid`,
 * `partial` and the `paid_at` stamp are all derived from the allocations, and
 * this is the only thing that recomputes them. That is the same arrangement
 * the overdue sweep uses: the fact lives in the data, and something walks it
 * to keep the stored copy honest so lists can filter and index on it.
 */
class PaymentService
{
    /**
     * Record a payment and apply it.
     *
     * All of it in one transaction: a payment row with no allocations is money
     * that has arrived and been put nowhere, and an allocation with no payment
     * is an invoice marked paid by nothing. Both are worse than a failure.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{invoice_id: string, amount_cents: int}>  $allocations
     *
     * @throws ValidationException
     */
    public function record(array $attributes, array $allocations, ?int $userId = null): Payment
    {
        return DB::transaction(function () use ($attributes, $allocations, $userId): Payment {
            $payment = Payment::create([...$attributes, 'recorded_by' => $userId]);

            foreach ($allocations as $allocation) {
                $this->allocate(
                    $payment,
                    Invoice::findOrFail($allocation['invoice_id']),
                    (int) $allocation['amount_cents'],
                );
            }

            return $payment->refresh();
        });
    }

    /**
     * Settle an invoice in full, as one payment.
     *
     * What the old `settle()` verb becomes. It is kept because the Billing
     * screen's one-click "mark settled" is still the common case — most
     * invoices are paid once, in full — but it now leaves a payment behind it
     * rather than a status with nothing underneath.
     *
     * The amount is the **balance**, not the invoice total: a document already
     * part-paid settles for what is left, and one whose customer withholds tax
     * settles for the gross less the withholding, because that is all that was
     * ever going to arrive.
     *
     * **The balance is read under a lock.** Two presses of "mark settled" a
     * moment apart used to both read the full balance and both record it, so
     * the invoice was paid twice and the second payment sat as a credit nobody
     * had sent. Locking the row first makes the second press wait, read a
     * zero balance, and be refused.
     *
     * @throws ValidationException
     */
    public function settle(Invoice $invoice, array $attributes = [], ?int $userId = null): Payment
    {
        return DB::transaction(function () use ($invoice, $attributes, $userId): Payment {
            $invoice = $this->lock($invoice);
            $balance = $invoice->balanceCents();

            if ($balance <= 0) {
                throw ValidationException::withMessages([
                    'invoice' => ["{$invoice->number} is already settled."],
                ]);
            }

            return $this->recordFor($invoice, $balance, $attributes, $userId);
        });
    }

    /**
     * The one payment that settles a locked invoice's balance.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function recordFor(Invoice $invoice, int $balance, array $attributes, ?int $userId): Payment
    {
        return $this->record(
            [
                'customer_id' => $invoice->customer_id,
                'direction' => $invoice->direction->value,
                'amount_cents' => $balance,
                'currency' => $invoice->currency,
                'paid_on' => $attributes['paid_on'] ?? now()->toDateString(),
                'method' => $attributes['method'] ?? 'bank_transfer',
                'reference' => $attributes['reference'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ],
            [['invoice_id' => $invoice->id, 'amount_cents' => $balance]],
            $userId,
        );
    }

    /**
     * Put part of a payment against one invoice.
     *
     * Two rules, and both are the kind of error that balances at a glance and
     * is wrong underneath:
     *
     *   **Not more than the invoice still owes.** Overpaying a document does
     *   not make it more paid; it hides money that belongs against the
     *   customer's next invoice, or back in their pocket. The overflow stays
     *   unallocated on the payment, where it can be seen and applied.
     *
     *   **Not more than the payment holds.** Allocating ₱80,000 of a ₱50,000
     *   cheque would settle invoices with money nobody sent.
     *
     * Applying the same payment to the same invoice twice **adds** rather than
     * inserting a second row. A double-click is one payment, not two.
     *
     * **Nothing goes against a cancelled document.** It used to be accepted,
     * and `refreshInvoice()` then derived `paid` or `pending` from the money —
     * silently un-cancelling a document somebody had deliberately withdrawn.
     *
     * Both rows are read **under a lock**, inside a transaction, so two
     * allocations racing each other cannot both see the same balance and both
     * spend it.
     *
     * @throws ValidationException
     */
    public function allocate(Payment $payment, Invoice $invoice, int $amountCents): PaymentAllocation
    {
        return DB::transaction(fn (): PaymentAllocation => $this->allocateLocked(
            $this->lock($payment),
            $this->lock($invoice),
            $amountCents,
        ));
    }

    /**
     * A fresh copy of the row, locked until the transaction ends.
     *
     * Fresh matters as much as locked: an invoice handed in from a list
     * carries the allocation sum it was loaded with, and a balance worked out
     * from that stale sum is exactly the race the lock is there to close.
     *
     * @template T of Invoice|Payment
     *
     * @param  T  $model
     * @return T
     */
    private function lock(Invoice|Payment $model): Invoice|Payment
    {
        return $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
    }

    /** @throws ValidationException */
    private function allocateLocked(Payment $payment, Invoice $invoice, int $amountCents): PaymentAllocation
    {
        if ($amountCents <= 0) {
            throw ValidationException::withMessages([
                'allocations' => ['An allocation has to be for more than nothing.'],
            ]);
        }

        if ($invoice->status === StatusValue::Cancelled) {
            throw ValidationException::withMessages([
                'allocations' => ["{$invoice->number} is cancelled, so nothing is owed on it."],
            ]);
        }

        $existing = PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->where('invoice_id', $invoice->id)
            ->first();

        $alreadyHere = $existing?->amount_cents ?? 0;

        // What the payment has left, treating anything already on this invoice
        // as available — otherwise a correction upwards would be refused by
        // the money it is itself replacing.
        $spare = $payment->unallocatedCents() + $alreadyHere;

        if ($amountCents > $spare) {
            throw ValidationException::withMessages([
                'allocations' => [sprintf(
                    'That is more than the payment has left to apply (%s).',
                    number_format($spare / 100, 2),
                )],
            ]);
        }

        if ($amountCents > $invoice->balanceCents() + $alreadyHere) {
            throw ValidationException::withMessages([
                'allocations' => [sprintf(
                    '%s only has %s outstanding.',
                    $invoice->number,
                    number_format(($invoice->balanceCents() + $alreadyHere) / 100, 2),
                )],
            ]);
        }

        $allocation = PaymentAllocation::updateOrCreate(
            ['payment_id' => $payment->id, 'invoice_id' => $invoice->id],
            ['amount_cents' => $amountCents],
        );

        $this->refreshInvoice($invoice);

        return $allocation;
    }

    /**
     * Un-apply a payment, and put the invoice back where it was.
     *
     * Deleting the payment removes its allocations by cascade, but the invoice
     * statuses it was holding up are stored — so they are recomputed here
     * before the row goes, while the allocations can still be read.
     */
    public function delete(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $invoices = $payment->invoices()->get();

            $payment->allocations()->delete();
            $payment->delete();

            foreach ($invoices as $invoice) {
                $this->refreshInvoice($invoice->fresh());
            }
        });
    }

    /**
     * Bring an invoice's stored status back in line with its allocations.
     *
     * `paid_at` is the date of the payment that closed it, not the moment
     * somebody pressed a button — a cheque entered on Friday for money that
     * cleared on Tuesday is Tuesday's collection, and every figure dated from
     * `paid_at` would otherwise land in the wrong week.
     *
     * A **cancelled** document is left cancelled. That is the one status a
     * person chooses rather than the money deciding, and removing a payment
     * from one — which `delete()` below does for every invoice it touched —
     * must not quietly reopen it. Reopening is `BillingService::update()`.
     */
    public function refreshInvoice(Invoice $invoice): Invoice
    {
        if ($invoice->status === StatusValue::Cancelled) {
            return $invoice;
        }

        // Dropped, so the balance below is summed afresh rather than read
        // from whatever a list query loaded onto the model.
        unset($invoice->allocations_sum_amount_cents);
        $invoice->load('allocations');

        $status = $invoice->statusFromPayments();

        $invoice->update([
            'status' => $status->value,
            'paid_at' => $status === StatusValue::Paid
                ? ($invoice->payments()->max('paid_on') ?? now()->toDateString())
                : null,
        ]);

        return $invoice->refresh();
    }
}
