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

/**
 * One person gets one component, over a stretch of time.
 *
 * The dates are the point. Without them a firm would have to remember to
 * unassign a loan on the payday it finishes repaying — which is to say it would
 * not, and somebody would be deducted for a thirteenth month.
 *
 * The amount is usually the catalogue's. An override here is for the person
 * whose figure is genuinely their own, and leaving it null is what keeps a
 * firm-wide raise to one edited row rather than ninety.
 */
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

    /**
     * Does this assignment apply to a period?
     *
     * Overlap, not containment. An allowance that starts on the 10th counts on
     * the payslip covering the 1st to the 15th — the person had it for part of
     * the period, and a fleet office pays the allowance for that period rather
     * than prorating it by days. Requiring the window to *contain* the period
     * would quietly skip the first payslip of every allowance ever granted, and
     * the person would have to notice.
     *
     * Proration is deliberately absent. A firm that wants a part-month figure
     * adjusts that one payslip by hand, which is what `adjustments_cents` is
     * for, rather than having every allowance silently divided by a day count
     * nobody asked about.
     */
    public function coversPeriod(Carbon $periodStart, Carbon $periodEnd): bool
    {
        if ($this->effective_from === null || $this->effective_from->gt($periodEnd)) {
            return false;
        }

        return $this->effective_to === null || $this->effective_to->gte($periodStart);
    }

    /** Assignments in force, without yet asking about any particular period. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }

    /**
     * Assignments whose window overlaps a period.
     *
     * The same rule as `coversPeriod()`, in SQL, so a run does not load every
     * assignment a company has ever made in order to throw most of them away.
     * The two are asserted to agree in the tests, because a filter that drifts
     * from its own predicate is how somebody stops being paid.
     */
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
