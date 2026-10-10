<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Services\LogoStore;
use App\Domain\Tenancy\Support\Tenant;

class InvoiceDocumentService
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly LogoStore $logos,
    ) {}

    /** @return array<string, mixed> */
    public function build(Invoice $invoice): array
    {
        $invoice->loadMissing([
            'customer',
            'trip.driver:id,name',
            'trip.vehicle:id,plate',
            'trip.customer:id,name',
            'trip.deliveryLog:id,trip_id,delivered_at',
            'allocations.payment.recordedBy:id,name',
        ]);

        $company = $this->tenant->company();

        return [
            'issuer' => [
                'name' => $company?->name,
                'code' => $company?->code,
                'tin' => $company?->tin,
                'vat_registered' => (bool) ($company?->vat_registered ?? false),
                'address' => $company?->address,
                'contact_name' => $company?->contact_name,
                'contact_phone' => $company?->contact_phone,
                'contact_email' => $company?->contact_email,
                'logo_url' => $this->logos->url($company?->logo_path),
            ],

            'bill_to' => [
                'name' => $invoice->counterparty(),
                'customer_id' => $invoice->customer_id,
                'address' => $invoice->customer?->address,
                'contact' => $invoice->customer?->contact,
                'tin' => $invoice->customer?->tin,
                'vat_treatment' => $invoice->customer?->vat_treatment?->value,
                'vat_treatment_label' => $invoice->customer?->vat_treatment?->label(),
                'withholds_tax' => (bool) ($invoice->customer?->withholds_tax ?? false),
            ],

            'document' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'title' => $this->title($invoice),
                'direction' => $invoice->direction->value,
                'issued_at' => $invoice->issued_at?->toDateString(),
                'due_at' => $invoice->due_at?->toDateString(),
                'terms_days' => $invoice->issued_at && $invoice->due_at
                    ? $invoice->issued_at->diffInDays($invoice->due_at)
                    : null,
                'status' => $invoice->status->value,
                'overdue' => $invoice->isOverdue(),
                'currency' => $invoice->currency,
            ],

            'lines' => $this->lines($invoice),

            'totals' => [
                'net_amount_cents' => $invoice->net_amount_cents,
                'vat_cents' => $invoice->vat_cents,
                'amount_cents' => $invoice->amount_cents,
                'withholding_cents' => $invoice->withholding_cents,
                'due_cents' => $invoice->dueCents(),
                'paid_cents' => $invoice->paidCents(),
                'balance_cents' => $invoice->balanceCents(),
                'vat_rate_bp' => $invoice->vat_rate_bp,
                'withholding_rate_bp' => $invoice->withholding_rate_bp,
                'vat_treatment' => $invoice->vat_treatment?->value,
                'vat_label' => $invoice->vat_treatment?->label(),
                'amount_in_words' => Money::inWords($invoice->dueCents(), $invoice->currency),
            ],

            'payments' => $this->payments($invoice),
        ];
    }

    private function title(Invoice $invoice): string
    {
        if ($invoice->direction->value === 'payable') {
            return 'Bill';
        }

        return $invoice->trip_id === null ? 'Sales Invoice' : 'Delivery Invoice';
    }

    /** @return array<int, array<string, mixed>> */
    private function lines(Invoice $invoice): array
    {
        $trip = $invoice->trip;

        if ($trip === null) {
            return [[
                'description' => 'Freight and hauling services',
                'reference' => null,
                'origin' => null,
                'destination' => null,
                'cargo' => null,
                'weight_kg' => null,
                'delivered_at' => null,
                'driver' => null,
                'plate' => null,
                'amount_cents' => $invoice->net_amount_cents,
            ]];
        }

        return [[
            'description' => sprintf('Hauling: %s to %s', $trip->origin, $trip->destination),
            'reference' => $trip->reference,
            'origin' => $trip->origin,
            'destination' => $trip->destination,
            'cargo' => $trip->cargo,
            'weight_kg' => $trip->weight_kg,
            'delivered_at' => ($trip->deliveryLog?->delivered_at ?? $trip->scheduled_at)?->toDateString(),
            'driver' => $trip->driver?->name,
            'plate' => $trip->vehicle?->plate,
            'amount_cents' => $invoice->net_amount_cents,
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function payments(Invoice $invoice): array
    {
        return $invoice->allocations
            ->sortBy(fn ($allocation) => (string) $allocation->payment?->paid_on)
            ->values()
            ->map(fn ($allocation): array => [
                'id' => $allocation->payment?->id,
                'paid_on' => $allocation->payment?->paid_on?->toDateString(),
                'method' => $allocation->payment?->method,
                'method_label' => $this->methodLabel($allocation->payment),
                'reference' => $allocation->payment?->reference,
                'notes' => $allocation->payment?->notes,
                'recorded_by' => $allocation->payment?->recordedBy?->name,
                'amount_cents' => (int) $allocation->amount_cents,
            ])
            ->all();
    }

    private function methodLabel(?Payment $payment): ?string
    {
        $method = $payment?->method;

        return $method === null ? null : ucfirst(str_replace('_', ' ', $method));
    }
}
