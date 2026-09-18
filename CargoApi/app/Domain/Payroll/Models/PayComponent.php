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

/**
 * One thing a firm pays or deducts every period — the catalogue row.
 *
 * A rice allowance, a COLA, a uniform deduction. Per company, because none of
 * it is the government's: two firms in the same yard pay a different rice
 * allowance and neither is wrong, which is the same test `payroll_deduct_on`
 * and the cutoff days pass.
 *
 * This row is **not** what a payslip says. It is what payroll should do *next*
 * time. What a given payslip actually carried is frozen onto
 * `PayRunLineComponent`, and the difference is the whole reason both tables
 * exist: editing a rice allowance today must change March's payroll and must
 * not change February's payslips.
 */
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

    /** Who this is assigned to. */
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

    /**
     * Does this earning go into the tax base?
     *
     * A deduction never does, whatever the column says — the flag is
     * meaningless there and is ignored rather than forbidden, so a form that
     * switches from an earning to a deduction does not have to hide a checkbox
     * to stay correct.
     */
    public function isTaxable(): bool
    {
        return $this->isEarning() && (bool) $this->taxable;
    }

    /**
     * The monthly figure for somebody on this basic, before the schedule
     * decides which payslip carries it.
     *
     * The override is the assignment's, where it has one — see
     * `EmployeePayComponent`. Passed in rather than read back through the
     * relation so this stays answerable for a component nobody is assigned.
     */
    public function monthlyCentsFor(int $monthlyBasicCents, ?int $amountOverride = null, ?int $rateOverride = null): int
    {
        return $this->basis->monthlyCents(
            $amountOverride ?? (int) $this->amount_cents,
            $rateOverride ?? (int) $this->rate_bp,
            $monthlyBasicCents,
        );
    }

    /** Components still in use, in the order the office put them. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }

    public function scopeInPayslipOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('name');
    }
}
