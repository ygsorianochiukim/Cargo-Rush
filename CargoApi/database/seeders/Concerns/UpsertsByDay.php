<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use Carbon\CarbonInterface;
use Database\Seeders\Demo\SupplierSeeder;
use Illuminate\Database\Eloquent\Model;

/**
 * `updateOrCreate` for a row keyed on a day.
 *
 * Written because the obvious version is not idempotent across databases. A
 * `date` column comes back as `2026-08-17` from MySQL and as
 * `2026-08-17 00:00:00` from SQLite, so
 *
 *     Model::updateOrCreate(['charged_on' => $day->toDateString()], [...])
 *
 * finds the row it wrote last time on one driver and inserts a second one on
 * the other — which is how a seeder that passes its own idempotency test in
 * SQLite quietly doubles a store tab against the real install, or trips a
 * unique index and halts halfway through.
 *
 * `whereDate` asks the database the question in its own terms, and both answer
 * it the same way.
 *
 * @see SupplierSeeder for the price history this keeps single.
 */
trait UpsertsByDay
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $match  the rest of the natural key
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
