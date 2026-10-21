<?php

declare(strict_types=1);

namespace App\Domain\Asset\Services;

use App\Domain\Asset\Models\Asset;
use Illuminate\Support\Carbon;

class AssetTagger
{
    public const PREFIX = 'Asset-INV-';

    public function next(Carbon|string|null $date = null): string
    {
        $last = Asset::withTrashed()
            ->where('tag', 'like', self::PREFIX.'%')
            ->lockForUpdate()
            ->pluck('tag')
            ->map(static fn (string $tag): int => preg_match('/-(\d+)$/', $tag, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return self::format($date, $last + 1);
    }

    public static function format(Carbon|string|null $date, int $number): string
    {
        $day = $date === null ? Carbon::today() : Carbon::parse($date);

        return self::PREFIX.$day->format('mdy').'-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }
}
