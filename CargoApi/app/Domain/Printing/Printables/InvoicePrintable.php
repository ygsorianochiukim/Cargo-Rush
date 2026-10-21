<?php

declare(strict_types=1);

namespace App\Domain\Printing\Printables;

use App\Domain\Billing\Services\InvoiceDocumentService;
use App\Domain\Printing\Contracts\Printable;
use App\Domain\Printing\Support\Format;
use App\Domain\Printing\Support\Layout as L;

class InvoicePrintable implements Printable
{
    public function __construct(private readonly InvoiceDocumentService $documents) {}

    public function key(): string
    {
        return 'invoice';
    }

    public function name(): string
    {
        return 'Invoice';
    }

    public function group(): string
    {
        return 'Billing';
    }

    public function description(): string
    {
        return 'Sales and delivery invoices, and supplier bills.';
    }

    public function orientation(): string
    {
        return 'portrait';
    }

    public function fields(): array
    {
        return [
            ...Format::companyFields(),
            'document.title' => 'Invoice title (Delivery Invoice, Sales Invoice, Bill)',
            'document.number' => 'Invoice number',
            'document.status' => 'Status',
            'document.issued_at' => 'Issue date',
            'document.due_at' => 'Due date',
            'document.terms' => 'Terms (e.g. 30 days)',
            'document.currency' => 'Currency',
            'customer.name' => 'Customer name',
            'customer.address' => 'Customer address',
            'customer.contact' => 'Customer contact',
            'customer.tin' => 'Customer TIN',
            'customer.vat' => 'Customer VAT treatment',
            'totals.net' => 'Net of VAT',
            'totals.vat' => 'VAT amount',
            'totals.vat_rate' => 'VAT rate',
            'totals.total' => 'Invoice total',
            'totals.withholding' => 'Withholding (blank when none)',
            'totals.withholding_rate' => 'Withholding rate',
            'totals.payable' => 'Amount payable',
            'totals.received' => 'Received (blank when unpaid)',
            'totals.balance' => 'Balance due (blank when unpaid)',
            'totals.in_words' => 'Amount payable in words',
            'today' => 'Date printed',
        ];
    }

    public function tables(): array
    {
        return [
            'lines' => [
                ['key' => 'description', 'label' => 'Description'],
                ['key' => 'reference', 'label' => 'Reference'],
                ['key' => 'delivered', 'label' => 'Delivered'],
                ['key' => 'weight', 'label' => 'Weight', 'align' => 'right'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right'],
            ],
            'payments' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'method', 'label' => 'Method'],
                ['key' => 'reference', 'label' => 'Reference'],
                ['key' => 'recorded_by', 'label' => 'Recorded by'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right'],
            ],
        ];
    }

    public function defaults(): array
    {
        $muted = ['size' => 8.5, 'color' => L::MUTED];
        $caption = ['size' => 7, 'color' => L::MUTED, 'upper' => true];
        $tables = $this->tables();

        return L::make([
            L::image('logo', 'Logo', 'top', 0, 0, 16, 16),
            L::text('company_name', 'Company name', 'top', 20, 0, 95, '{{company.name}}', ['size' => 14, 'bold' => true]),
            L::text('company_details', 'Company details', 'top', 20, 7, 95, "{{company.address}}\n{{company.phone}} {{company.email}}\nTIN {{company.tin}}", $muted),
            L::text('title', 'Title', 'top', 112, 0, 70, '{{document.title}}', ['size' => 8, 'bold' => true, 'upper' => true, 'align' => 'right', 'color' => L::ACCENT]),
            L::text('number', 'Invoice number', 'top', 102, 5, 80, '{{document.number}}', ['size' => 16, 'bold' => true, 'align' => 'right']),
            L::text('status', 'Status', 'top', 112, 13, 70, '{{document.status}}', ['size' => 8, 'bold' => true, 'align' => 'right', 'color' => L::ACCENT]),
            L::line('rule_top', 'Line under header', 'top', 0, 28, 182),
            L::text('billed_to_label', 'Customer heading', 'top', 0, 33, 100, 'Billed to', $caption),
            L::text('customer_name', 'Customer name', 'top', 0, 37, 100, '{{customer.name}}', ['size' => 11, 'bold' => true]),
            L::text('customer_details', 'Customer details', 'top', 0, 43, 100, "{{customer.address}}\n{{customer.contact}}\nTIN {{customer.tin}}\n{{customer.vat}}", $muted),
            L::text('dates_labels', 'Date labels', 'top', 118, 33, 30, "Issued\nDue\nTerms{{?document.terms}}\nCurrency", ['size' => 7.5, 'color' => L::MUTED, 'upper' => true, 'line_height' => 1.6]),
            L::text('dates', 'Dates', 'top', 148, 33, 34, "{{document.issued_at}}\n{{document.due_at}}\n{{document.terms}}\n{{document.currency}}", ['size' => 8.5, 'align' => 'right', 'line_height' => 1.45]),
            L::line('rule_mid', 'Line under customer', 'top', 0, 62, 182),
            L::text('lines_heading', 'Lines heading', 'top', 0, 66, 182, 'For the following delivery', $caption),

            L::table('lines', 'Delivery lines', 'lines', $tables['lines'], 1, ['space_before' => 0]),
            L::table('payments', 'Payments received', 'payments', $tables['payments'], 2, ['title' => 'Payments received', 'space_before' => 6, 'color' => L::RULE]),

            L::line('rule_totals', 'Line above totals', 'bottom', 0, 0, 182),
            L::text('words_label', 'Amount in words heading', 'bottom', 0, 4, 95, 'Amount in words', $caption),
            L::text('words', 'Amount in words', 'bottom', 0, 8, 95, '{{totals.in_words}}', ['size' => 10]),
            L::text('withholding_note', 'Withholding note', 'bottom', 0, 18, 95, '{{totals.withholding_rate}} creditable withholding tax has been deducted. The balance is remitted to the BIR by the payer, and Form 2307 is expected with the payment.', $muted),
            L::text('totals_labels', 'Totals labels', 'bottom', 110, 4, 42, "Net of VAT\nVAT ({{totals.vat_rate}})\nInvoice total\nLess withholding{{?totals.withholding}}", ['size' => 8.5, 'color' => L::MUTED, 'line_height' => 1.7]),
            L::text('totals', 'Totals', 'bottom', 150, 4, 32, "{{totals.net}}\n{{totals.vat}}\n{{totals.total}}\n{{totals.withholding}}", ['size' => 8.5, 'align' => 'right', 'line_height' => 1.7]),
            L::text('payable_label', 'Amount payable label', 'bottom', 110, 26.5, 42, 'Amount payable', ['size' => 9, 'bold' => true]),
            L::text('payable', 'Amount payable', 'bottom', 142, 26, 40, '{{totals.payable}}', ['size' => 11, 'bold' => true, 'align' => 'right', 'color' => L::ACCENT]),
            L::text('balance_labels', 'Balance labels', 'bottom', 110, 33, 42, "Received{{?totals.received}}\nBalance due{{?totals.balance}}", ['size' => 8.5, 'color' => L::MUTED, 'line_height' => 1.7]),
            L::text('balance', 'Balance', 'bottom', 150, 33, 32, "{{totals.received}}\n{{totals.balance}}", ['size' => 8.5, 'bold' => true, 'align' => 'right', 'line_height' => 1.7]),
            L::text('issued_by_label', 'Issued by heading', 'bottom', 0, 44, 80, 'Issued by', $caption),
            L::line('issued_by_line', 'Issued by line', 'bottom', 0, 56, 80, '#8a96a5'),
            L::text('issued_by', 'Issued by name', 'bottom', 0, 57, 80, '{{company.contact_name}}', $muted),
            L::text('received_by_label', 'Received by heading', 'bottom', 102, 44, 80, 'Received by', $caption),
            L::line('received_by_line', 'Received by line', 'bottom', 102, 56, 80, '#8a96a5'),
            L::text('received_by', 'Received by hint', 'bottom', 102, 57, 80, 'Name, signature and date', $muted),
            L::text('footer', 'Footer', 'bottom', 0, 66, 182, '{{document.number}} · {{company.name}} · This document was generated by Cargo Rush.', ['size' => 7, 'color' => L::MUTED, 'align' => 'center']),
        ], 72, 72);
    }

    public function sample(): array
    {
        return $this->data($this->documents->sample());
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    public function data(array $doc): array
    {
        $currency = $doc['document']['currency'] ?? 'PHP';
        $money = static fn (?int $cents): string => Format::money($cents, $currency);
        $totals = $doc['totals'];
        $paid = (int) $totals['paid_cents'] > 0;
        $withheld = (int) $totals['withholding_cents'] > 0;

        $values = [
            ...Format::companyValues(),
            'document.title' => (string) $doc['document']['title'],
            'document.number' => (string) $doc['document']['number'],
            'document.status' => ucfirst(str_replace('_', ' ', (string) $doc['document']['status'])),
            'document.issued_at' => Format::date($doc['document']['issued_at']),
            'document.due_at' => Format::date($doc['document']['due_at']),
            'document.terms' => $doc['document']['terms_days'] !== null ? $doc['document']['terms_days'].' days' : '',
            'document.currency' => (string) $currency,
            'customer.name' => (string) $doc['bill_to']['name'],
            'customer.address' => (string) ($doc['bill_to']['address'] ?? ''),
            'customer.contact' => (string) ($doc['bill_to']['contact'] ?? ''),
            'customer.tin' => (string) ($doc['bill_to']['tin'] ?? ''),
            'customer.vat' => (string) ($doc['bill_to']['vat_treatment_label'] ?? ''),
            'totals.net' => $money($totals['net_amount_cents']),
            'totals.vat' => $money($totals['vat_cents']),
            'totals.vat_rate' => Format::rate($totals['vat_rate_bp']),
            'totals.total' => $money($totals['amount_cents']),
            'totals.withholding' => $withheld ? '−'.$money($totals['withholding_cents']) : '',
            'totals.withholding_rate' => $withheld ? Format::rate($totals['withholding_rate_bp']) : '',
            'totals.payable' => $money($totals['due_cents']),
            'totals.received' => $paid ? '−'.$money($totals['paid_cents']) : '',
            'totals.balance' => $paid ? $money($totals['balance_cents']) : '',
            'totals.in_words' => (string) $totals['amount_in_words'],
        ];

        $lines = array_map(static fn (array $line) => [
            'description' => implode("\n", array_filter([
                $line['description'],
                $line['cargo'] ?? '',
                implode(' · ', array_filter([$line['plate'] ? 'Unit '.$line['plate'] : '', $line['driver'] ?? ''])),
            ])),
            'reference' => (string) ($line['reference'] ?? '—'),
            'delivered' => Format::date($line['delivered_at']),
            'weight' => $line['weight_kg'] === null ? '—' : number_format((float) $line['weight_kg']).' kg',
            'amount' => $money($line['amount_cents']),
        ], $doc['lines']);

        $payments = array_map(static fn (array $p) => [
            'date' => Format::date($p['paid_on']),
            'method' => (string) ($p['method_label'] ?? '—'),
            'reference' => (string) ($p['reference'] ?? '—'),
            'recorded_by' => (string) ($p['recorded_by'] ?? '—'),
            'amount' => $money($p['amount_cents']),
        ], $doc['payments']);

        return [
            'pages' => [[
                'values' => $values,
                'tables' => [
                    'lines' => ['rows' => $lines],
                    'payments' => ['rows' => $payments],
                ],
            ]],
        ];
    }
}
