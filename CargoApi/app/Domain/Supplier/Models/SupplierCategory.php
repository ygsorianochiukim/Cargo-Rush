<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Models;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What kind of place a supplier is — GARAGE, MALL, FOODS.
 *
 * The firm's own words, added on Access Control. It labels and orders the shop
 * pickers; it carries no money and posts nothing.
 */
class SupplierCategory extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = ['name', 'status'];

    protected function casts(): array
    {
        return ['status' => StatusValue::class];
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }
}
