<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    /**
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

    /** @throws ValidationException */
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

    /** @param array<string, mixed> $attributes */
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

    /** @throws ValidationException */
    public function allocate(Payment $payment, Invoice $invoice, int $amountCents): PaymentAllocation
    {
        return DB::transaction(fn (): PaymentAllocation => $this->allocateLocked(
            $this->lock($payment),
            $this->lock($invoice),
            $amountCents,
        ));
    }

    /**
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

    public function refreshInvoice(Invoice $invoice): Invoice
    {
        if ($invoice->status === StatusValue::Cancelled) {
            return $invoice;
        }

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
