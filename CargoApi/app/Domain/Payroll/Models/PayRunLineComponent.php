<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Shared\Enums\PayComponentKind;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One itemised line on a payslip — frozen, like everything else on one.
 *
 * "Rice allowance ₱1,000.00", as it was on the day. The name, the kind and the
 * amount are **copied** off the catalogue rather than read back through it, and
 * the link to `pay_components` is for opening the thing, never for reading it:
 * renaming a component, changing its amount, or deleting it outright must not
 * touch a payslip somebody has already been handed.
 *
 * That is the same rule the employee's name and position on `PayRunLine`
 * follow, and it is worth restating here because this is the table where it is
 * most tempting to break — a join would be one line shorter and would quietly
 * restate every payslip in the archive the first time an office tidied its
 * catalogue.
 */
class PayRunLineComponent extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'pay_run_line_id', 'pay_component_id', 'name', 'kind', 'taxable', 'amount_cents',
        'added_by_hand',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PayComponentKind::class,
            'taxable' => 'boolean',
            'amount_cents' => 'integer',
            /**
             * Did somebody type this onto the payslip, rather than it coming
             * from an assignment?
             *
             * The flag `build()` reads before it throws every line away and
             * works the run out again: a hand-added row has nothing to
             * recompute it from, so it is carried across instead. See the
             * migration.
             */
            'added_by_hand' => 'boolean',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PayRunLine::class, 'pay_run_line_id');
    }

    /**
     * The catalogue row this came from, where it still exists.
     *
     * Null once the component has been deleted, and the payslip is unaffected —
     * see the note at the top.
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }

    public function isEarning(): bool
    {
        return $this->kind === PayComponentKind::Earning;
    }
}
