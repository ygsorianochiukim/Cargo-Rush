<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\BalanceSide;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'type', 'group', 'description', 'position', 'status', 'is_system',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'status' => StatusValue::class,
            'is_system' => 'boolean',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function normalBalance(): BalanceSide
    {
        return $this->type->normalBalance();
    }

    public function isActive(): bool
    {
        return $this->status === StatusValue::Active;
    }

    public function hasPostings(): bool
    {
        return $this->lines()->exists();
    }

    public function label(): string
    {
        return $this->code.' · '.$this->name;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }

    public function scopeInChartOrder(Builder $query): Builder
    {
        $order = collect(AccountType::cases())
            ->map(static fn (AccountType $type): string => sprintf(
                "WHEN '%s' THEN %d",
                $type->value,
                $type->position(),
            ))
            ->implode(' ');

        return $query
            ->orderByRaw('CASE '.$query->getQuery()->getGrammar()->wrap('accounts.type')." {$order} ELSE 99 END")
            ->orderBy('position')
            ->orderBy('code');
    }
}
