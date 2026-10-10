<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

trait UpsertsByDay
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $values
     */
    protected function upsertOn(
        string $model,
        array $match,
        string $dateColumn,
        CarbonInterface $day,
        array $values,
    ): Model {
        $existing = $model::query()
            ->where($match)
            ->whereDate($dateColumn, $day->toDateString())
            ->first();

        if ($existing !== null) {
            $existing->fill($values)->save();

            return $existing;
        }

        return $model::create([
            ...$match,
            $dateColumn => $day->toDateString(),
            ...$values,
        ]);
    }
}
