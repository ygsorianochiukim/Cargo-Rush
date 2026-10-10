<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Shared\Enums\PayComponentBasis;
use App\Domain\Shared\Enums\PayComponentKind;
use App\Domain\Shared\Enums\PayComponentSchedule;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayComponent extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'name', 'kind', 'basis', 'amount_cents', 'rate_bp',
        'schedule', 'taxable', 'status', 'position', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PayComponentKind::class,
            'basis' => PayComponentBasis::class,
            'schedule' => PayComponentSchedule::class,
            'status' => StatusValue::class,
            'amount_cents' => 'integer',
            'rate_bp' => 'integer',
            'position' => 'integer',
            'taxable' => 'boolean',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeePayComponent::class);
    }

    public function isEarning(): bool
    {
        return $this->kind === PayComponentKind::Earning;
    }

    public function isActive(): bool
    {
        return $this->status === StatusValue::Active;
    }

    public function isTaxable(): bool
    {
        return $this->isEarning() && (bool) $this->taxable;
    }

    public function monthlyCentsFor(int $monthlyBasicCents, ?int $amountOverride = null, ?int $rateOverride = null): int
    {
        return $this->basis->monthlyCents(
            $amountOverride ?? (int) $this->amount_cents,
            $rateOverride ?? (int) $this->rate_bp,
            $monthlyBasicCents,
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }

    public function scopeInPayslipOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('name');
    }
}
