<?php

declare(strict_types=1);

namespace App\Domain\Printing\Printables;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Services\StatementOfAccountService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Printing\Contracts\Printable;
use App\Domain\Printing\Support\Format;
use App\Domain\Printing\Support\Layout as L;
use App\Domain\Shared\Enums\InvoiceDirection;

class StatementOfAccountPrintable implements Printable
{
    private const BUCKETS = [
        'current' => 'Not yet due',
        'days_1_30' => '1–30 days',
        'days_31_60' => '31–60 days',
        'days_61_90' => '61–90 days',
        'over_90' => 'Over 90 days',
    ];

    public function __construct(private readonly StatementOfAccountService $accounts) {}

    public function key(): string
    {
        return 'statement-of-account';
    }

    public function name(): string
    {
        return 'Statement of account';
    }

    public function group(): string
    {
        return 'Billing';
    }

    public function description(): string
    {
        return 'Every charge and payment on one customer or supplier, with the running balance.';
    }

    public function orientation(): string
    {
        return 'portrait';
    }

    public function fields(): array
    {
        return [
            ...Format::companyFields(),
            'statement.title' => 'Statement of Account / Supplier Statement',
            'statement.period' => 'Period (e.g. Jan 1, 2026 – Oct 21, 2026)',
            'account.name' => 'Customer or supplier name',
            'account.address' => 'Their address',
            'account.contact' => 'Their contact',
            'account.tin' => 'Their TIN',
            'totals.opening' => 'Opening balance',
            'totals.charges' => 'Charges in the period',
            'totals.credits' => 'Payments and credits in the period',
            'totals.closing' => 'Closing balance',
            'totals.closing_label' => 'Amount due from / we owe this account',
            'totals.in_words' => 'Closing balance in words',
            'aging.current' => 'Not yet due',
            'aging.days_1_30' => '1–30 days late',
            'aging.days_31_60' => '31–60 days late',
            'aging.days_61_90' => '61–90 days late',
            'aging.over_90' => 'Over 90 days late',
            'today' => 'Date printed',
        ];
    }

    public function tables(): array
    {
        return [
            'lines' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'reference', 'label' => 'Reference'],
                ['key' => 'detail', 'label' => 'Detail'],
                ['key' => 'charge', 'label' => 'Charge', 'align' => 'right'],
                ['key' => 'credit', 'label' => 'Credit', 'align' => 'right'],
                ['key' => 'balance', 'label' => 'Balance', 'align' => 'right'],
            ],
        ];
    }

    public function defaults(): array
    {
        $muted = ['size' => 8.5, 'color' => L::MUTED];
        $caption = ['size' => 7, 'upper' => true, 'color' => L::MUTED];

        $elements = [
            L::image('logo', 'Logo', 'top', 0, 0, 16, 16),
            L::text('company_name', 'Company name', 'top', 20, 0, 95, '{{company.name}}', ['size' => 14, 'bold' => true]),
            L::text('company_details', 'Company details', 'top', 20, 7, 95, "{{company.address}}\n{{company.phone}} {{company.email}}\nTIN {{company.tin}}", $muted),
            L::text('title', 'Title', 'top', 112, 0, 70, '{{statement.title}}', ['size' => 8, 'bold' => true, 'upper' => true, 'align' => 'right', 'color' => L::ACCENT]),
            L::text('period', 'Period', 'top', 92, 5, 90, '{{statement.period}}', ['size' => 11, 'bold' => true, 'align' => 'right']),
            L::line('rule_top', 'Line under header', 'top', 0, 26, 182),
            L::text('account_label', 'Account heading', 'top', 0, 30, 100, 'Account', $caption),
            L::text('account_name', 'Account name', 'top', 0, 34, 100, '{{account.name}}', ['size' => 11, 'bold' => true]),
            L::text('account_details', 'Account details', 'top', 0, 40, 100, "{{account.address}}\n{{account.contact}}\nTIN {{account.tin}}", $muted),
            L::text('summary_labels', 'Summary labels', 'top', 105, 30, 42, "Opening balance\nCharges\nPayments and credits", ['size' => 8.5, 'color' => L::MUTED, 'line_height' => 1.7]),
            L::text('summary', 'Summary', 'top', 147, 30, 35, "{{totals.opening}}\n{{totals.charges}}\n{{totals.credits}}", ['size' => 8.5, 'align' => 'right', 'line_height' => 1.7]),

            L::table('lines', 'Account lines', 'lines', $this->tables()['lines'], 1, ['space_before' => 0]),

            L::line('rule_closing', 'Line above closing balance', 'bottom', 0, 1, 182, '#9aa6b4', 0.5),
            L::text('closing_label', 'Closing balance heading', 'bottom', 0, 4, 120, '{{totals.closing_label}}', $caption),
            L::text('closing_words', 'Closing balance in words', 'bottom', 0, 8, 120, '{{totals.in_words}}', $muted),
            L::text('closing', 'Closing balance', 'bottom', 112, 4, 70, '{{totals.closing}}', ['size' => 15, 'bold' => true, 'align' => 'right']),
            [...L::line('aging_box', 'Aging panel', 'bottom', 0, 17, 182), 'type' => 'box', 'h' => 17, 'fill' => '#eef6ff', 'color' => '#eef6ff'],
            L::text('aging_label', 'Aging heading', 'bottom', 3, 19, 120, 'Aging of the balance', $caption),
        ];

        $x = 3;

        foreach (self::BUCKETS as $bucket => $label) {
            $elements[] = L::text('aging_'.$bucket, $label, 'bottom', $x, 24, 34, $label."\n{{aging.".$bucket.'}}', ['size' => 8, 'color' => L::INK]);
            $x += 35.5;
        }

        $elements[] = L::text('footer', 'Footer', 'bottom', 0, 38, 182, '{{company.name}} · Printed {{today}}', ['size' => 7, 'color' => L::MUTED, 'align' => 'center']);

        return L::make($elements, 58, 44);
    }

    public function sample(): array
    {
        $customerId = Invoice::query()
            ->where('direction', InvoiceDirection::Receivable->value)
            ->whereNotNull('customer_id')
            ->latest('issued_at')
            ->value('customer_id');

        $customer = $customerId !== null ? Customer::query()->find($customerId) : null;

        $customer ??= new Customer([
            'name' => 'Sample Customer Trading',
            'address' => 'J.P. Laurel Ave, Davao City',
            'contact' => '0917 555 0101',
            'tin' => '123-456-789-000',
        ]);

        return $this->data($this->accounts->forCustomer($customer, now()->startOfYear(), now()->startOfDay()));
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    public function data(array $statement): array
    {
        $money = static fn (int $cents): string => Format::money($cents);
        $range = $statement['range'];

        $values = [
            ...Format::companyValues(),
            'company.name' => (string) ($statement['issuer']['name'] ?? ''),
            'company.address' => (string) ($statement['issuer']['address'] ?? ''),
            'company.phone' => (string) ($statement['issuer']['contact_phone'] ?? ''),
            'company.email' => (string) ($statement['issuer']['contact_email'] ?? ''),
            'company.tin' => (string) ($statement['issuer']['tin'] ?? ''),
            'statement.title' => (string) $statement['title'],
            'statement.period' => $range['from']
                ? Format::date($range['from']).' – '.Format::date($range['to'])
                : 'To '.Format::date($range['to']),
            'account.name' => (string) $statement['account']['name'],
            'account.address' => (string) ($statement['account']['address'] ?? ''),
            'account.contact' => (string) ($statement['account']['contact'] ?? ''),
            'account.tin' => (string) ($statement['account']['tin'] ?? ''),
            'totals.opening' => $money((int) $statement['opening_balance_cents']),
            'totals.charges' => $money((int) $statement['charges_cents']),
            'totals.credits' => $money((int) $statement['credits_cents']),
            'totals.closing' => $money((int) $statement['closing_balance_cents']),
            'totals.closing_label' => (string) $statement['balance_label'],
            'totals.in_words' => (string) $statement['closing_in_words'],
        ];

        foreach (array_keys(self::BUCKETS) as $bucket) {
            $values['aging.'.$bucket] = $money((int) $statement['aging'][$bucket]);
        }

        $rows = [];

        if ((int) $statement['opening_balance_cents'] !== 0) {
            $rows[] = ['detail' => 'Balance brought forward', 'balance' => $money((int) $statement['opening_balance_cents'])];
        }

        foreach ($statement['lines'] as $line) {
            $rows[] = [
                'date' => Format::date($line['date']),
                'reference' => (string) $line['reference'],
                'detail' => (string) $line['detail'].(($line['due_at'] ?? null) ? "\ndue ".Format::date($line['due_at']) : ''),
                'charge' => $line['charge_cents'] ? $money((int) $line['charge_cents']) : '—',
                'credit' => $line['credit_cents'] ? $money((int) $line['credit_cents']) : '—',
                'balance' => $money((int) $line['balance_cents']),
            ];
        }

        if ($rows === []) {
            $rows[] = ['detail' => 'Nothing on this account in this period.'];
        }

        return [
            'pages' => [[
                'values' => $values,
                'tables' => ['lines' => ['rows' => $rows]],
            ]],
        ];
    }
}
