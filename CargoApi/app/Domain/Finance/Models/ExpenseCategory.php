<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseCategory extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = ['key', 'name', 'description', 'icon', 'position', 'status', 'account_code'];

    public const DEFAULT_ACCOUNTS = [
        'food' => '5040',
        'lodging' => '5040',
        'toll-parking' => '5070',
        'permits' => '5080',
        'office' => '5210',
        'truck-rental' => '5215',
        'repairs' => '5050',
        'fuel' => '5010',
        'supplies' => '5900',
        'other' => '5900',
    ];

    public const FALLBACK_ACCOUNT = '5900';

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

    public function accountCode(): string
    {
        $code = trim((string) $this->account_code);

        return $code !== '' ? $code : (self::DEFAULT_ACCOUNTS[$this->key] ?? self::FALLBACK_ACCOUNT);
    }

    public function isActive(): bool
    {
        return $this->status === StatusValue::Active;
    }
}
