<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

trait AdoptsTrashedRows
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $match
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
