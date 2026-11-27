<?php

declare(strict_types=1);

namespace App\Domain\Billing\Controllers;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Requests\InvoiceRequest;
use App\Domain\Billing\Resources\InvoiceResource;
use App\Domain\Billing\Services\BillingService;
use App\Domain\Billing\Services\InvoiceDocumentService;
use App\Domain\Billing\Services\StatementOfAccountService;
use App\Domain\Billing\Support\BillingCutoff;
use App\Domain\Customer\Models\Customer;
use App\Domain\Printing\Printables\InvoicePrintable;
use App\Domain\Printing\Services\PrintRenderer;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillingController extends ApiController
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly InvoiceDocumentService $documents,
        private readonly StatementOfAccountService $statements,
    ) {}

    public function statement(Request $request, Customer $customer): JsonResponse
    {
        $direction = $request->string('direction')->toString() === InvoiceDirection::Payable->value
            ? InvoiceDirection::Payable
            : InvoiceDirection::Receivable;

        return $this->payload($this->statements->forCustomer(
            $customer,
            $this->date($request, 'from'),
            $this->date($request, 'to'),
            $direction,
        ));
    }

    private function date(Request $request, string $key): ?Carbon
    {
        $value = trim((string) $request->query($key, ''));

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public function document(Invoice $invoice): JsonResponse
    {
        return $this->payload($this->documents->build($invoice));
    }

    public function documentPdf(Invoice $invoice, InvoicePrintable $printable, PrintRenderer $renderer): Response
    {
        $doc = $this->documents->build($invoice);

        return $renderer->download($printable, $printable->data($doc), ($doc['document']['number'] ?? 'invoice').'.pdf');
    }

    public function export(Request $request): StreamedResponse
    {
        $invoices = $this->billing->all($this->filters($request));
        $selectedCutoff = $request->string('cutoff')->toString();

        $filename = sprintf('invoices-%s.csv', now()->format('Y-m-d'));

        return response()->streamDownload(function () use ($invoices, $selectedCutoff): void {
            $out = fopen('php://output', 'wb');

            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Number', 'Direction', 'Status', 'Customer', 'Trip',
                'Issued', 'Due', 'Currency',
                'Net', 'VAT', 'Total', 'Withholding', 'Due to us', 'Paid', 'Balance',
                'VAT treatment', 'Paid on',
                'Cutoff start', 'Cutoff end', 'Billing from', 'Billing through', 'Recommended payment date', 'Trip billing status',
            ]);

            foreach ($invoices as $invoice) {
                $cutoff = BillingCutoff::forInvoice($invoice);
                if ($selectedCutoff !== '' && $selectedCutoff !== ($cutoff['start'] ?? 'none')) {
                    continue;
                }

                fputcsv($out, [
                    $invoice->number,
                    $invoice->direction->value,
                    $invoice->status->value,
                    $invoice->counterparty(),
                    $invoice->trip?->reference,
                    $invoice->issued_at?->toDateString(),
                    $invoice->due_at?->toDateString(),
                    $invoice->currency,
                    $this->pesos($invoice->net_amount_cents),
                    $this->pesos($invoice->vat_cents),
                    $this->pesos($invoice->amount_cents),
                    $this->pesos($invoice->withholding_cents),
                    $this->pesos($invoice->dueCents()),
                    $this->pesos($invoice->paidCents()),
                    $this->pesos($invoice->balanceCents()),
                    $invoice->vat_treatment?->value,
                    $invoice->paid_at?->toDateString(),
                    $cutoff['start'] ?? null,
                    $cutoff['end'] ?? null,
                    $cutoff['billing_from'] ?? null,
                    $cutoff['billing_to'] ?? null,
                    $cutoff['payment_target'] ?? null,
                    $invoice->tripBillingStatus(),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function pesos(?int $cents): string
    {
        return number_format(((int) $cents) / 100, 2, '.', '');
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->billing->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(InvoiceResource::collection($page), $page);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return $this->item(new InvoiceResource($invoice));
    }

    public function store(InvoiceRequest $request): JsonResponse
    {
        return $this->item(new InvoiceResource($this->billing->create($request->toData())), status: 201);
    }

    public function update(InvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        return $this->item(new InvoiceResource($this->billing->update($invoice, $request->toData())));
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->billing->delete($invoice);

        return $this->noContent();
    }

    public function settle(Request $request, Invoice $invoice): JsonResponse
    {
        $details = $request->validate([
            'paid_on' => ['sometimes', 'date'],
            'method' => ['sometimes', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->item(new InvoiceResource(
            $this->billing->settle($invoice, $details, $request->user()?->getAuthIdentifier()),
        ));
    }

    public function updateTripBilling(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoice_ids' => ['required', 'array', 'list'],
            'invoice_ids.*' => ['required', 'ulid', 'distinct:ignore_case'],
            'action' => ['required', 'in:billed,paid'],
            'paid_on' => ['nullable', 'date_format:Y-m-d'],
            'method' => ['required_if:action,paid', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:80'],
        ]);

        $this->billing->updateTripBilling(
            $data['invoice_ids'],
            $data['action'],
            Arr::only($data, ['paid_on', 'method', 'reference']),
            $request->user()?->getAuthIdentifier(),
        );

        return $this->noContent();
    }

    public function totals(): JsonResponse
    {
        return $this->payload($this->billing->totals());
    }

    public function aging(Request $request): JsonResponse
    {
        $direction = $request->string('direction')->toString() === InvoiceDirection::Payable->value
            ? InvoiceDirection::Payable
            : InvoiceDirection::Receivable;

        return $this->payload($this->billing->aging($direction));
    }
}
