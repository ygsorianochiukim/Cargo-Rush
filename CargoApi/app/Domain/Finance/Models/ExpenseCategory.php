<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** What a peso went on: food, fuel, tolls, permits. */
class ExpenseCategory extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = ['key', 'name', 'description', 'icon', 'position', 'status'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'status' => StatusValue::class,
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id');
    }

    public function isActive(): bool
    {
        return $this->status === StatusValue::Active;
    }
}
