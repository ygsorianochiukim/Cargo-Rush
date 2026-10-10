<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Hr\Models\Employee;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class EmployeePayComponent extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'employee_id', 'pay_component_id', 'amount_cents', 'rate_bp',
        'effective_from', 'effective_to', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'amount_cents' => 'integer',
            'rate_bp' => 'integer',
            'status' => StatusValue::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }

    public function isActive(): bool
    {
        return $this->status === StatusValue::Active;
    }

    public function coversPeriod(Carbon $periodStart, Carbon $periodEnd): bool
    {
        if ($this->effective_from === null || $this->effective_from->gt($periodEnd)) {
            return false;
        }

        return $this->effective_to === null || $this->effective_to->gte($periodStart);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }

    public function scopeCoveringPeriod(Builder $query, Carbon $periodStart, Carbon $periodEnd): Builder
    {
        return $query
            ->whereDate('effective_from', '<=', $periodEnd->toDateString())
            ->where(function (Builder $query) use ($periodStart): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $periodStart->toDateString());
            });
    }
}
