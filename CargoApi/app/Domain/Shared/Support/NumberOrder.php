<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

use App\Domain\Trip\Models\Trip;
use Illuminate\Database\Eloquent\Builder;

final class NumberOrder
{
    public static function newestFirst(Builder $query, string $column): Builder
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($query->getModel()->getTable().'.'.$column);
        $tail = "SUBSTR({$wrapped}, INSTR({$wrapped}, '-') + 1)";

        return $query
            ->orderByRaw("CASE WHEN {$wrapped} IS NULL THEN 1 ELSE 0 END")
            ->orderByRaw("LENGTH({$tail}) DESC")
            ->orderByRaw("{$tail} DESC");
    }

    public static function newestTripFirst(Builder $query, string $tripKey = 'trip_id'): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->orderByDesc(
            Trip::withTrashed()
                ->select('reference_seq')
                ->whereColumn('trips.id', "{$table}.{$tripKey}")
                ->limit(1),
        );
    }
}
