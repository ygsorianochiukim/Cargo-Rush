<?php

declare(strict_types=1);

namespace App\Domain\Printing\Printables;

use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Services\AssetReportService;
use App\Domain\Printing\Contracts\Printable;
use App\Domain\Printing\Printables\Concerns\DrawsBoxes;
use App\Domain\Printing\Support\Format;
use App\Domain\Printing\Support\Layout as L;
use Illuminate\Support\Collection;

class AssetReportPrintable implements Printable
{
    use DrawsBoxes;

    private const STATUS = [
        'assigned' => 'Issued',
        'available' => 'In stock',
        'maintenance' => 'Under repair',
        'inactive' => 'Retired / lost',
    ];

    public function __construct(private readonly AssetReportService $reports) {}

    public function key(): string
    {
        return 'asset-report';
    }

    public function name(): string
    {
        return 'Asset report';
    }

    public function group(): string
    {
        return 'Assets';
    }

    public function description(): string
    {
        return 'The asset register: stock by category, age, purchases, custodians and the full list.';
    }

    public function orientation(): string
    {
        return 'landscape';
    }

    public function fields(): array
    {
        return [
            ...Format::companyFields(),
            'totals.count' => 'Items on the register',
            'totals.value' => 'Total purchase value',
            'totals.issued' => 'Items issued',
            'totals.in_stock' => 'Items in stock',
            'totals.repair' => 'Items under repair',
            'totals.retired' => 'Items retired or lost',
            'today' => 'Date printed',
        ];
    }

    public function tables(): array
    {
        return [
            'categories' => [
                ['key' => 'category', 'label' => 'Category'],
                ['key' => 'count', 'label' => 'Items', 'align' => 'right'],
                ['key' => 'issued', 'label' => 'Issued', 'align' => 'right'],
                ['key' => 'in_stock', 'label' => 'In stock', 'align' => 'right'],
                ['key' => 'repair', 'label' => 'Under repair', 'align' => 'right'],
                ['key' => 'retired', 'label' => 'Retired / lost', 'align' => 'right'],
                ['key' => 'value', 'label' => 'Value', 'align' => 'right'],
            ],
            'ages' => [
                ['key' => 'age', 'label' => 'Age'],
                ['key' => 'count', 'label' => 'Items', 'align' => 'right'],
                ['key' => 'value', 'label' => 'Value', 'align' => 'right'],
            ],
            'years' => [
                ['key' => 'year', 'label' => 'Year'],
                ['key' => 'count', 'label' => 'Items', 'align' => 'right'],
                ['key' => 'value', 'label' => 'Spent', 'align' => 'right'],
            ],
            'custodians' => [
                ['key' => 'custodian', 'label' => 'Custodian'],
                ['key' => 'position', 'label' => 'Position'],
                ['key' => 'count', 'label' => 'Items', 'align' => 'right'],
                ['key' => 'damaged', 'label' => 'Damaged', 'align' => 'right'],
                ['key' => 'since', 'label' => 'Holding since'],
                ['key' => 'value', 'label' => 'Value held', 'align' => 'right'],
            ],
            'assets' => [
                ['key' => 'tag', 'label' => 'Tag'],
                ['key' => 'item', 'label' => 'Item'],
                ['key' => 'category', 'label' => 'Category'],
                ['key' => 'held_by', 'label' => 'Held by'],
                ['key' => 'purchased', 'label' => 'Purchased'],
                ['key' => 'condition', 'label' => 'Condition'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'cost', 'label' => 'Cost', 'align' => 'right'],
            ],
        ];
    }

    public function defaults(): array
    {
        $tables = $this->tables();
        $caption = ['size' => 7, 'color' => L::MUTED, 'upper' => true];

        $elements = [
            L::text('title', 'Title', 'top', 0, 0, 200, 'Asset Report', ['size' => 15, 'bold' => true]),
            L::text('subtitle', 'Subtitle', 'top', 0, 8.5, 277, '{{company.name}} · as of {{today}} · retired and lost items included at cost', ['size' => 8, 'color' => L::MUTED]),
        ];

        $tiles = [
            ['count', 'Items', '{{totals.count}}'],
            ['value', 'Total value', '{{totals.value}}'],
            ['issued', 'Issued', '{{totals.issued}}'],
            ['in_stock', 'In stock', '{{totals.in_stock}}'],
            ['repair', 'Under repair', '{{totals.repair}}'],
            ['retired', 'Retired / lost', '{{totals.retired}}'],
        ];

        foreach ($tiles as $i => [$id, $label, $value]) {
            $x = $i * 46.4;
            $elements[] = self::box("tile_{$id}_box", "{$label} tile", 'top', $x, 15, 44.5, 16);
            $elements[] = L::text("tile_{$id}_label", "{$label} label", 'top', $x + 2.5, 17.5, 40, $label, $caption);
            $elements[] = L::text("tile_{$id}", $label, 'top', $x + 2.5, 22, 40, $value, ['size' => 12, 'bold' => true]);
        }

        $elements[] = L::table('categories', 'Stock by category', 'categories', $tables['categories'], 1, ['title' => 'Stock by category', 'space_before' => 0, 'size' => 8]);
        $elements[] = L::table('ages', 'Age of assets', 'ages', $tables['ages'], 2, ['title' => 'Age of assets', 'space_before' => 6, 'size' => 8]);
        $elements[] = L::table('years', 'Purchases by year', 'years', $tables['years'], 3, ['title' => 'Purchases by year', 'space_before' => 6, 'size' => 8]);
        $elements[] = L::table('custodians', 'Accountability by custodian', 'custodians', $tables['custodians'], 4, ['title' => 'Accountability by custodian', 'space_before' => 6, 'size' => 8]);
        $elements[] = L::table('assets', 'Asset list', 'assets', $tables['assets'], 5, ['title' => 'Asset list', 'space_before' => 6, 'size' => 8]);

        $elements[] = L::text('footer', 'Footer', 'bottom', 0, 1, 277, '{{company.name}} · Asset report printed {{today}}', ['size' => 7, 'color' => L::MUTED, 'align' => 'center']);

        return L::make($elements, 35, 6, 10);
    }

    public function sample(): array
    {
        $assets = Asset::query()->with(['category', 'custodian'])->orderBy('tag')->orderBy('name')->get();

        if ($assets->isEmpty()) {
            return $this->fake();
        }

        return $this->data($this->reports->report(), $assets);
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  Collection<int, Asset>  $assets
     * @return array<string, mixed>
     */
    public function data(array $report, Collection $assets): array
    {
        $money = static fn (int $cents): string => Format::money($cents);
        $totals = $report['totals'];

        return [
            'pages' => [[
                'values' => [
                    ...Format::companyValues(),
                    'totals.count' => (string) $totals['count'],
                    'totals.value' => $money((int) $totals['cost_cents']),
                    'totals.issued' => (string) $totals['assigned'],
                    'totals.in_stock' => (string) $totals['available'],
                    'totals.repair' => (string) $totals['maintenance'],
                    'totals.retired' => (string) $totals['inactive'],
                ],
                'tables' => [
                    'categories' => ['rows' => array_map(static fn (array $c) => [
                        'category' => (string) $c['name'],
                        'count' => (string) $c['count'],
                        'issued' => (string) $c['assigned'],
                        'in_stock' => (string) $c['available'],
                        'repair' => (string) $c['maintenance'],
                        'retired' => (string) $c['inactive'],
                        'value' => $money((int) $c['cost_cents']),
                    ], $report['by_category'])],
                    'ages' => ['rows' => array_map(static fn (array $a) => [
                        'age' => (string) $a['label'],
                        'count' => (string) $a['count'],
                        'value' => $money((int) $a['cost_cents']),
                    ], $report['by_age'])],
                    'years' => ['rows' => array_map(static fn (array $y) => [
                        'year' => (string) $y['year'],
                        'count' => (string) $y['count'],
                        'value' => $money((int) $y['cost_cents']),
                    ], $report['by_year'])],
                    'custodians' => ['rows' => array_map(static fn (array $p) => [
                        'custodian' => (string) $p['name'],
                        'position' => (string) ($p['position'] ?? '—'),
                        'count' => (string) $p['count'],
                        'damaged' => (string) $p['damaged'],
                        'since' => Format::date($p['held_since']),
                        'value' => $money((int) $p['cost_cents']),
                    ], $report['by_custodian'])],
                    'assets' => ['rows' => $assets->map(static fn (Asset $a) => [
                        'tag' => (string) ($a->tag ?? '—'),
                        'item' => (string) $a->name,
                        'category' => (string) ($a->category?->name ?? '—'),
                        'held_by' => (string) ($a->custodian?->fullName() ?? 'In stock'),
                        'purchased' => Format::date($a->purchased_on),
                        'condition' => ucfirst((string) $a->condition),
                        'status' => self::STATUS[$a->status->value] ?? ucfirst($a->status->value),
                        'cost' => $a->cost_cents === null ? '—' : $money((int) $a->cost_cents),
                    ])->values()->all()],
                ],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function fake(): array
    {
        $money = static fn (int $cents): string => Format::money($cents);

        return [
            'pages' => [[
                'values' => [
                    ...Format::companyValues(),
                    'totals.count' => '3',
                    'totals.value' => $money(5_000_000),
                    'totals.issued' => '2',
                    'totals.in_stock' => '1',
                    'totals.repair' => '0',
                    'totals.retired' => '0',
                ],
                'tables' => [
                    'categories' => ['rows' => [
                        ['category' => 'IT EQUIPMENT', 'count' => '2', 'issued' => '2', 'in_stock' => '0', 'repair' => '0', 'retired' => '0', 'value' => $money(4_850_000)],
                        ['category' => 'TOOLS', 'count' => '1', 'issued' => '0', 'in_stock' => '1', 'repair' => '0', 'retired' => '0', 'value' => $money(150_000)],
                    ]],
                    'ages' => ['rows' => [
                        ['age' => 'Under 1 year', 'count' => '2', 'value' => $money(4_400_000)],
                        ['age' => '1–3 years', 'count' => '1', 'value' => $money(600_000)],
                    ]],
                    'years' => ['rows' => [
                        ['year' => (string) now()->year, 'count' => '2', 'value' => $money(4_400_000)],
                    ]],
                    'custodians' => ['rows' => [
                        ['custodian' => 'Juan Dela Cruz', 'position' => 'Office Staff', 'count' => '2', 'damaged' => '0', 'since' => Format::date(now()->subMonths(2)), 'value' => $money(4_850_000)],
                    ]],
                    'assets' => ['rows' => [
                        ['tag' => 'Asset-INV-'.now()->format('mdy').'-0001', 'item' => 'Lenovo ThinkPad E14', 'category' => 'IT EQUIPMENT', 'held_by' => 'Juan Dela Cruz', 'purchased' => Format::date(now()), 'condition' => 'Good', 'status' => 'Issued', 'cost' => $money(4_250_000)],
                        ['tag' => 'Asset-INV-'.now()->format('mdy').'-0002', 'item' => 'Two-way radio', 'category' => 'IT EQUIPMENT', 'held_by' => 'Juan Dela Cruz', 'purchased' => Format::date(now()), 'condition' => 'Good', 'status' => 'Issued', 'cost' => $money(600_000)],
                        ['tag' => 'Asset-INV-'.now()->format('mdy').'-0003', 'item' => 'Floor jack', 'category' => 'TOOLS', 'held_by' => 'In stock', 'purchased' => '—', 'condition' => 'Good', 'status' => 'In stock', 'cost' => $money(150_000)],
                    ]],
                ],
            ]],
        ];
    }
}
