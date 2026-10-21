<?php

declare(strict_types=1);

namespace App\Domain\Printing\Printables;

use App\Domain\Accounting\Services\FinancialStatementService;
use App\Domain\Printing\Contracts\Printable;
use App\Domain\Printing\Printables\Concerns\AccountRows;
use App\Domain\Printing\Support\Format;
use App\Domain\Printing\Support\Layout as L;

class IncomeStatementPrintable implements Printable
{
    use AccountRows;

    public function __construct(private readonly FinancialStatementService $statements) {}

    public function key(): string
    {
        return 'income-statement';
    }

    public function name(): string
    {
        return 'Income statement';
    }

    public function group(): string
    {
        return 'Accounting';
    }

    public function description(): string
    {
        return 'Revenue, expenses and net income for a period.';
    }

    public function orientation(): string
    {
        return 'portrait';
    }

    public function fields(): array
    {
        return [
            ...Format::companyFields(),
            'period.label' => 'Period (e.g. Jan 1, 2026 to Oct 21, 2026)',
            'totals.revenue' => 'Total revenue',
            'totals.expenses' => 'Total expenses',
            'totals.net_income' => 'Net income',
            'totals.net_margin' => 'Net margin (blank when no revenue)',
            'today' => 'Date printed',
        ];
    }

    public function tables(): array
    {
        return [
            'revenue' => self::accountColumns(),
            'expenses' => self::accountColumns(),
            'summary' => [
                ['key' => 'label', 'label' => 'Summary'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right'],
            ],
        ];
    }

    public function defaults(): array
    {
        $tables = $this->tables();

        return L::make([
            L::text('title', 'Title', 'top', 0, 0, 120, 'Income Statement', ['size' => 8, 'bold' => true, 'upper' => true, 'color' => L::ACCENT]),
            L::text('period', 'Period', 'top', 0, 5, 140, '{{period.label}}', ['size' => 15, 'bold' => true]),
            L::text('company', 'Company name', 'top', 0, 13, 140, '{{company.name}}', ['size' => 9, 'color' => L::MUTED]),
            L::line('rule_top', 'Line under header', 'top', 0, 20, 182),

            L::table('revenue', 'Revenue', 'revenue', $tables['revenue'], 1, ['title' => 'Revenue', 'space_before' => 2]),
            L::table('expenses', 'Expenses', 'expenses', $tables['expenses'], 2, ['title' => 'Expenses', 'space_before' => 7]),
            L::table('summary', 'Summary', 'summary', $tables['summary'], 3, ['space_before' => 7, 'color' => L::RULE]),

            L::text('footer', 'Footer', 'bottom', 0, 2, 182, '{{company.name}} · Printed {{today}}', ['size' => 7, 'color' => L::MUTED, 'align' => 'center']),
        ], 24, 8);
    }

    public function sample(): array
    {
        return $this->data($this->statements->incomeStatement(now()->startOfYear(), now()->startOfDay()));
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    public function data(array $statement): array
    {
        $range = $statement['range'];
        $revenue = (int) $statement['revenue']['total_cents'];
        $expenses = (int) $statement['expenses']['total_cents'];
        $cost = $statement['cost_of_services_cents'];

        $summary = [];

        if ($cost !== null) {
            $summary[] = ['label' => 'Cost of services', 'amount' => Format::money((int) $cost)];
            $summary[] = [
                'label' => 'Gross profit',
                'amount' => Format::money((int) ($statement['gross_profit_cents'] ?? 0))
                    .($statement['gross_margin_pct'] !== null ? ' ('.$statement['gross_margin_pct'].'%)' : ''),
            ];
            $summary[] = ['label' => 'Operating and other expenses', 'amount' => Format::money($expenses - (int) $cost)];
        } else {
            $summary[] = ['label' => 'Cost of services', 'amount' => 'not grouped in the chart'];
        }

        $netMargin = $statement['net_margin_pct'] !== null ? $statement['net_margin_pct'].'%' : '';

        return [
            'pages' => [[
                'values' => [
                    ...Format::companyValues(),
                    'period.label' => $range['from']
                        ? Format::date($range['from']).' to '.Format::date($range['to'])
                        : 'Everything to '.Format::date($range['to'] ?? now()->toDateString()),
                    'totals.revenue' => Format::money($revenue),
                    'totals.expenses' => Format::money($expenses),
                    'totals.net_income' => Format::money((int) $statement['net_income_cents']),
                    'totals.net_margin' => $netMargin,
                ],
                'tables' => [
                    'revenue' => [
                        'rows' => self::accountRows($statement['revenue']['groups'], true, 'Nothing billed in this period.'),
                        'foot' => [self::totalRow('Total revenue', $revenue)],
                    ],
                    'expenses' => [
                        'rows' => self::accountRows($statement['expenses']['groups'], true, 'Nothing spent in this period.'),
                        'foot' => [self::totalRow('Total expenses', $expenses)],
                    ],
                    'summary' => [
                        'rows' => $summary,
                        'foot' => [[
                            'label' => 'Net income',
                            'amount' => Format::money((int) $statement['net_income_cents']).($netMargin !== '' ? ' ('.$netMargin.')' : ''),
                        ]],
                    ],
                ],
            ]],
        ];
    }
}
