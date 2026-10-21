<?php

declare(strict_types=1);

namespace App\Domain\Printing\Printables;

use App\Domain\Billing\Services\BillingService;
use App\Domain\Printing\Contracts\Printable;
use App\Domain\Printing\Support\Format;
use App\Domain\Printing\Support\Layout as L;
use App\Domain\Shared\Enums\InvoiceDirection;

class AgingReportPrintable implements Printable
{
    private const BUCKETS = [
        'current' => 'Not yet due',
        '1_30' => '1–30 days',
        '31_60' => '31–60 days',
        '61_90' => '61–90 days',
        'over_90' => 'Over 90 days',
    ];

    public function __construct(private readonly BillingService $billing) {}

    public function key(): string
    {
        return 'aging-report';
    }

    public function name(): string
    {
        return 'Aging report';
    }

    public function group(): string
    {
        return 'Billing';
    }

    public function description(): string
    {
        return 'What is outstanding, by how late it is, per customer or supplier.';
    }

    public function orientation(): string
    {
        return 'portrait';
    }

    public function fields(): array
    {
        return [
            ...Format::companyFields(),
            'aging.heading' => 'Owed to us / Owed by us',
            'aging.by' => 'By customer / By supplier',
            'aging.current' => 'Not yet due',
            'aging.1_30' => '1–30 days late',
            'aging.31_60' => '31–60 days late',
            'aging.61_90' => '61–90 days late',
            'aging.over_90' => 'Over 90 days late',
            'aging.total' => 'Total outstanding',
            'today' => 'Date printed',
        ];
    }

    public function tables(): array
    {
        return [
            'accounts' => [
                ['key' => 'account', 'label' => 'Account'],
                ['key' => 'current', 'label' => 'Not due', 'align' => 'right'],
                ['key' => '1_30', 'label' => '1–30', 'align' => 'right'],
                ['key' => '31_60', 'label' => '31–60', 'align' => 'right'],
                ['key' => '61_90', 'label' => '61–90', 'align' => 'right'],
                ['key' => 'over_90', 'label' => '90+', 'align' => 'right'],
                ['key' => 'total', 'label' => 'Total', 'align' => 'right'],
            ],
        ];
    }

    public function defaults(): array
    {
        $elements = [
            L::text('title', 'Title', 'top', 0, 0, 120, 'Aging Report', ['size' => 8, 'bold' => true, 'upper' => true, 'color' => L::ACCENT]),
            L::text('heading', 'Heading', 'top', 0, 5, 182, '{{aging.heading}} · as of {{today}}', ['size' => 15, 'bold' => true]),
            L::text('company', 'Company name', 'top', 0, 13, 182, '{{company.name}}', ['size' => 9, 'color' => L::MUTED]),
            L::line('rule_top', 'Line under header', 'top', 0, 20, 182),
        ];

        $x = 0;

        foreach (self::BUCKETS as $bucket => $label) {
            $elements[] = [...L::line('tile_'.$bucket.'_box', $label.' box', 'top', $x, 24, 33.8), 'type' => 'box', 'h' => 13, 'fill' => null, 'color' => L::RULE];
            $elements[] = L::text('tile_'.$bucket.'_label', $label.' label', 'top', $x + 2.5, 26, 30, $label, ['size' => 6.5, 'upper' => true, 'color' => L::MUTED]);
            $elements[] = L::text('tile_'.$bucket, $label.' amount', 'top', $x + 2.5, 30.5, 31, '{{aging.'.$bucket.'}}', ['size' => 8.5, 'bold' => true]);
            $x += 36.9;
        }

        $elements[] = L::text('by', 'Table heading', 'top', 0, 41, 182, '{{aging.by}}', ['size' => 7, 'upper' => true, 'color' => L::MUTED]);
        $elements[] = L::table('accounts', 'Outstanding by account', 'accounts', $this->tables()['accounts'], 1, ['space_before' => 0]);
        $elements[] = L::text('footer', 'Footer', 'bottom', 0, 2, 182, '{{company.name}} · Printed {{today}}', ['size' => 7, 'color' => L::MUTED, 'align' => 'center']);

        return L::make($elements, 46, 8);
    }

    public function sample(): array
    {
        return $this->data($this->billing->aging(InvoiceDirection::Receivable), InvoiceDirection::Receivable);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function data(array $report, InvoiceDirection $direction): array
    {
        $receivable = $direction === InvoiceDirection::Receivable;
        $money = static fn (int $cents): string => Format::money($cents);

        $values = [
            ...Format::companyValues(),
            'aging.heading' => $receivable ? 'Owed to us' : 'Owed by us',
            'aging.by' => $receivable ? 'By customer' : 'By supplier',
            'aging.total' => $money((int) $report['total_cents']),
        ];

        foreach (array_keys(self::BUCKETS) as $bucket) {
            $values['aging.'.$bucket] = $money((int) $report['buckets'][$bucket]);
        }

        $rows = array_map(static function (array $row) use ($money): array {
            $line = ['account' => (string) $row['counterparty'], 'total' => $money((int) $row['total_cents'])];

            foreach (array_keys(self::BUCKETS) as $bucket) {
                $line[$bucket] = $row[$bucket] ? $money((int) $row[$bucket]) : '—';
            }

            return $line;
        }, $report['by_counterparty']);

        if ($rows === []) {
            $rows[] = ['account' => 'Nothing outstanding. Every document in this direction has been settled.'];
        }

        return [
            'pages' => [[
                'values' => $values,
                'tables' => [
                    'accounts' => [
                        'rows' => $rows,
                        'foot' => [['account' => 'Total outstanding', 'total' => $money((int) $report['total_cents'])]],
                    ],
                ],
            ]],
        ];
    }
}
