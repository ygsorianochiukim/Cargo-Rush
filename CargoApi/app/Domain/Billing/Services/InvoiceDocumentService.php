<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Support\InvoiceTemplate;
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
        $template = InvoiceTemplate::defaults();

        return [
            'template' => $template,

            'issuer' => [
                'name' => $company?->name,
                'code' => $company?->code,
                'tin' => $company?->tin,
                'vat_registered' => (bool) ($company?->vat_registered ?? false),
                'address' => $company?->address,
                'contact_name' => $company?->contact_name,
                'contact_phone' => $company?->contact_phone,
                'contact_email' => $company?->contact_email,
                'logo_url' => $this->logos->url($this->logoPath()),
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
                'title' => $template['titles'][$this->titleKey($invoice)],
                'title_key' => $this->titleKey($invoice),
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

    public function logoPath(): ?string
    {
        $company = $this->tenant->company();

        return $company?->logo_path;
    }

    /** @return array<string, mixed> */
    public function sample(): array
    {
        $latest = Invoice::query()
            ->where('direction', 'receivable')
            ->whereNotNull('trip_id')
            ->latest('issued_at')
            ->first();

        if ($latest !== null) {
            return $this->build($latest);
        }

        $company = $this->tenant->company();
        $template = InvoiceTemplate::defaults();
        $today = now();

        return [
            'template' => $template,
            'issuer' => [
                'name' => $company?->name,
                'code' => $company?->code,
                'tin' => $company?->tin,
                'vat_registered' => (bool) ($company?->vat_registered ?? false),
                'address' => $company?->address,
                'contact_name' => $company?->contact_name,
                'contact_phone' => $company?->contact_phone,
                'contact_email' => $company?->contact_email,
                'logo_url' => $this->logos->url($this->logoPath()),
            ],
            'bill_to' => [
                'name' => 'Sample Customer Trading',
                'customer_id' => null,
                'address' => 'J.P. Laurel Ave, Davao City',
                'contact' => '0917 555 0101',
                'tin' => '123-456-789-000',
                'vat_treatment' => 'vatable',
                'vat_treatment_label' => 'VAT-able',
                'withholds_tax' => false,
            ],
            'document' => [
                'id' => 'sample',
                'number' => 'INV-'.$today->year.'-0001',
                'title' => $template['titles']['delivery'],
                'title_key' => 'delivery',
                'direction' => 'receivable',
                'issued_at' => $today->toDateString(),
                'due_at' => $today->copy()->addDays(30)->toDateString(),
                'terms_days' => 30,
                'status' => 'pending',
                'overdue' => false,
                'currency' => 'PHP',
            ],
            'lines' => [[
                'description' => 'Hauling: Davao City to Tagum City',
                'reference' => 'CTS-'.$today->format('mdy').'-00001',
                'origin' => 'Davao City',
                'destination' => 'Tagum City',
                'cargo' => 'Bagged rice, 200 sacks',
                'weight_kg' => 10000,
                'delivered_at' => $today->toDateString(),
                'driver' => 'Juan Dela Cruz',
                'plate' => 'NCR 4412',
                'amount_cents' => 1_250_000,
            ]],
            'totals' => [
                'net_amount_cents' => 1_250_000,
                'vat_cents' => 150_000,
                'amount_cents' => 1_400_000,
                'withholding_cents' => 0,
                'due_cents' => 1_400_000,
                'paid_cents' => 0,
                'balance_cents' => 1_400_000,
                'vat_rate_bp' => 1200,
                'withholding_rate_bp' => 0,
                'vat_treatment' => 'vatable',
                'vat_label' => 'VAT-able',
                'amount_in_words' => Money::inWords(1_400_000, 'PHP'),
            ],
            'payments' => [],
        ];
    }

    private function titleKey(Invoice $invoice): string
    {
        if ($invoice->direction->value === 'payable') {
            return 'bill';
        }

        return $invoice->trip_id === null ? 'sales' : 'delivery';
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
