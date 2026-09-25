<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `updateOrCreate`, for a table where somebody may have deleted the row.
 *
 * Almost everything here soft-deletes, and almost every natural key a seeder
 * matches on is behind a unique index — `employees.employee_no`,
 * `vehicles.plate`, `trips.reference`, `truckers.licence_no`, `suppliers.name`.
 * Those two facts together are a trap:
 *
 *     Employee::updateOrCreate(['employee_no' => 'DEMO-01'], [...])
 *
 * does not see a deleted row, so it inserts — and the index does see it, so the
 * insert fails and takes the whole seeding run down with it. The failure is not
 * hypothetical and not rare: deleting the demo staff off a dev install and
 * seeding again is exactly what somebody does after a confusing walkthrough,
 * and it is the one moment a seeder has to be reliable.
 *
 * So this looks with `withTrashed()`, brings the row back if it was deleted,
 * and updates it. A demo row somebody removed and then re-seeded is a row they
 * asked for again.
 *
 * `CarrierSeeder` has done the same for `companies.code` since it was written,
 * for the same reason and in the same words. This is that, generalised.
 */
trait AdoptsTrashedRows
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $match  the natural key
     * @param  array<string, mixed>  $values
     */
    protected function restoreOrCreate(string $model, array $match, array $values = []): Model
    {
        $query = $model::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        $existing = $query->where($match)->first();

        if ($existing === null) {
            return $model::create([...$match, ...$values]);
        }

        if (method_exists($existing, 'trashed') && $existing->trashed()) {
            $existing->restore();
        }

        $existing->fill($values)->save();

        return $existing;
    }
}
