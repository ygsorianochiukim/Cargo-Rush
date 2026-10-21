<?php

declare(strict_types=1);

namespace App\Domain\Printing\Printables;

use App\Domain\Accounting\Services\FinancialStatementService;
use App\Domain\Printing\Contracts\Printable;
use App\Domain\Printing\Printables\Concerns\AccountRows;
use App\Domain\Printing\Support\Format;
use App\Domain\Printing\Support\Layout as L;

class BalanceSheetPrintable implements Printable
{
    use AccountRows;

    public function __construct(private readonly FinancialStatementService $statements) {}

    public function key(): string
    {
        return 'balance-sheet';
    }

    public function name(): string
    {
        return 'Balance sheet';
    }

    public function group(): string
    {
        return 'Accounting';
    }

    public function description(): string
    {
        return 'Assets, liabilities and equity as of a date.';
    }

    public function orientation(): string
    {
        return 'portrait';
    }

    public function fields(): array
    {
        return [
            ...Format::companyFields(),
            'sheet.as_of' => 'As-of date',
            'sheet.check' => 'Balanced, or how far out it is',
            'totals.assets' => 'Total assets',
            'totals.liabilities' => 'Total liabilities',
            'totals.equity' => 'Total equity',
            'totals.liabilities_and_equity' => 'Liabilities and equity',
            'today' => 'Date printed',
        ];
    }

    public function tables(): array
    {
        return [
            'assets' => self::accountColumns(),
            'liabilities' => self::accountColumns(),
            'equity' => self::accountColumns(),
        ];
    }

    public function defaults(): array
    {
        $tables = $this->tables();

        return L::make([
            L::text('title', 'Title', 'top', 0, 0, 120, 'Balance Sheet', ['size' => 8, 'bold' => true, 'upper' => true, 'color' => L::ACCENT]),
            L::text('as_of', 'As-of date', 'top', 0, 5, 130, 'As of {{sheet.as_of}}', ['size' => 15, 'bold' => true]),
            L::text('company', 'Company name', 'top', 0, 13, 130, '{{company.name}}', ['size' => 9, 'color' => L::MUTED]),
            L::text('check', 'Balanced badge', 'top', 122, 6, 60, '{{sheet.check}}', ['size' => 8.5, 'bold' => true, 'align' => 'right', 'color' => L::ACCENT]),
            L::line('rule_top', 'Line under header', 'top', 0, 20, 182),

            L::table('assets', 'Assets', 'assets', $tables['assets'], 1, ['title' => 'Assets', 'space_before' => 2]),
            L::table('liabilities', 'Liabilities', 'liabilities', $tables['liabilities'], 2, ['title' => 'Liabilities', 'space_before' => 7]),
            L::table('equity', 'Equity', 'equity', $tables['equity'], 3, ['title' => 'Equity', 'space_before' => 7]),

            L::line('rule_total', 'Line above grand total', 'bottom', 0, 1, 182, '#9aa6b4', 0.5),
            L::text('total_label', 'Grand total label', 'bottom', 0, 3, 120, 'Liabilities and equity', ['size' => 10, 'bold' => true]),
            L::text('total', 'Grand total', 'bottom', 112, 3, 70, '{{totals.liabilities_and_equity}}', ['size' => 10, 'bold' => true, 'align' => 'right']),
            L::text('footer', 'Footer', 'bottom', 0, 12, 182, '{{company.name}} · Printed {{today}}', ['size' => 7, 'color' => L::MUTED, 'align' => 'center']),
        ], 24, 18);
    }

    public function sample(): array
    {
        return $this->data($this->statements->balanceSheet(now()->startOfDay()));
    }

    /**
     * @param  array<string, mixed>  $sheet
     * @return array<string, mixed>
     */
    public function data(array $sheet): array
    {
        $equityRows = self::accountRows($sheet['equity']['groups'], false, '');
        $equityRows[] = [
            'code' => '',
            'account' => "Earnings not yet closed\nEverything earned and spent up to this date.\nNo period close has swept it into retained earnings.",
            'amount' => Format::money((int) $sheet['equity']['earnings_not_closed_cents']),
        ];

        return [
            'pages' => [[
                'values' => [
                    ...Format::companyValues(),
                    'sheet.as_of' => Format::date($sheet['as_of']),
                    'sheet.check' => $sheet['balanced'] ? 'Balanced' : 'Out by '.Format::money((int) $sheet['difference_cents']),
                    'totals.assets' => Format::money((int) $sheet['assets']['total_cents']),
                    'totals.liabilities' => Format::money((int) $sheet['liabilities']['total_cents']),
                    'totals.equity' => Format::money((int) $sheet['equity']['total_cents']),
                    'totals.liabilities_and_equity' => Format::money((int) $sheet['liabilities_and_equity_cents']),
                ],
                'tables' => [
                    'assets' => [
                        'rows' => self::accountRows($sheet['assets']['groups'], false, 'Nothing held.'),
                        'foot' => [self::totalRow('Total assets', (int) $sheet['assets']['total_cents'])],
                    ],
                    'liabilities' => [
                        'rows' => self::accountRows($sheet['liabilities']['groups'], false, 'Nothing owed.'),
                        'foot' => [self::totalRow('Total liabilities', (int) $sheet['liabilities']['total_cents'])],
                    ],
                    'equity' => [
                        'rows' => $equityRows,
                        'foot' => [self::totalRow('Total equity', (int) $sheet['equity']['total_cents'])],
                    ],
                ],
            ]],
        ];
    }
}
