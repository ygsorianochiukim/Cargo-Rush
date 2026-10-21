<?php

declare(strict_types=1);

namespace App\Domain\Asset\Services;

use App\Domain\Asset\Models\Asset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AssetReportService
{
    private const STATUSES = ['assigned', 'available', 'maintenance', 'inactive'];

    private const AGES = [
        'under-1' => 'Under 1 year',
        '1-3' => '1–3 years',
        '3-5' => '3–5 years',
        'over-5' => 'Over 5 years',
        'unknown' => 'No purchase date',
    ];

    /** @return array<string, mixed> */
    public function report(): array
    {
        $assets = Asset::query()->with(['category', 'custodian'])->get();

        return [
            'totals' => $this->tally($assets),
            'by_category' => $this->byCategory($assets),
            'by_custodian' => $this->byCustodian($assets),
            'by_condition' => $this->counted($assets, 'condition', ['good', 'fair', 'damaged']),
            'by_year' => $this->byYear($assets),
            'by_age' => $this->byAge($assets),
        ];
    }

    /** @return array<string, int> */
    private function tally(Collection $assets): array
    {
        $tally = ['count' => $assets->count(), 'cost_cents' => (int) $assets->sum('cost_cents')];

        foreach (self::STATUSES as $status) {
            $tally[$status] = $assets->filter(fn (Asset $a) => $a->status->value === $status)->count();
        }

        return $tally;
    }

    /** @return array<int, array<string, mixed>> */
    private function byCategory(Collection $assets): array
    {
        return $assets
            ->groupBy(fn (Asset $a) => $a->category_id ?? '')
            ->map(fn (Collection $rows, string $id) => [
                'category_id' => $id === '' ? null : $id,
                'name' => $rows->first()->category?->name ?? 'Uncategorised',
                ...$this->tally($rows),
            ])
            ->sortByDesc('cost_cents')
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function byCustodian(Collection $assets): array
    {
        return $assets
            ->filter(fn (Asset $a) => $a->custodian_id !== null)
            ->groupBy('custodian_id')
            ->map(function (Collection $rows, string $id): array {
                $person = $rows->first()->custodian;

                return [
                    'employee_id' => $id,
                    'name' => $person?->fullName() ?? 'Former employee',
                    'position' => $person?->position,
                    'department' => $person?->department,
                    'count' => $rows->count(),
                    'cost_cents' => (int) $rows->sum('cost_cents'),
                    'damaged' => $rows->where('condition', 'damaged')->count(),
                    'held_since' => $rows->min(fn (Asset $a) => $a->assigned_on?->toDateString()),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @param  string[]  $keys
     * @return array<int, array{key: string, count: int, cost_cents: int}>
     */
    private function counted(Collection $assets, string $column, array $keys): array
    {
        return array_map(fn (string $key) => [
            'key' => $key,
            'count' => $assets->where($column, $key)->count(),
            'cost_cents' => (int) $assets->where($column, $key)->sum('cost_cents'),
        ], $keys);
    }

    /** @return array<int, array{year: int, count: int, cost_cents: int}> */
    private function byYear(Collection $assets): array
    {
        return $assets
            ->filter(fn (Asset $a) => $a->purchased_on !== null)
            ->groupBy(fn (Asset $a) => $a->purchased_on->year)
            ->map(fn (Collection $rows, int $year) => [
                'year' => $year,
                'count' => $rows->count(),
                'cost_cents' => (int) $rows->sum('cost_cents'),
            ])
            ->sortByDesc('year')
            ->values()
            ->all();
    }

    /** @return array<int, array{key: string, label: string, count: int, cost_cents: int}> */
    private function byAge(Collection $assets): array
    {
        $today = Carbon::today();

        $bucket = static function (Asset $a) use ($today): string {
            if ($a->purchased_on === null) {
                return 'unknown';
            }

            $years = $a->purchased_on->diffInYears($today);

            return match (true) {
                $years < 1 => 'under-1',
                $years < 3 => '1-3',
                $years < 5 => '3-5',
                default => 'over-5',
            };
        };

        $grouped = $assets->groupBy($bucket);

        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => self::AGES[$key],
            'count' => $grouped->get($key, collect())->count(),
            'cost_cents' => (int) $grouped->get($key, collect())->sum('cost_cents'),
        ], array_keys(self::AGES));
    }
}
