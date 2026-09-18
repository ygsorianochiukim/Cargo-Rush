<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Hr\Models\Employee;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\PayComponentKind;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person's pay on one run — the payslip, as a row.
 *
 * Every figure on it is **copied**, including the person's name and position.
 * That is not duplication for its own sake: a payslip is a statement about a
 * fortnight, and it has to keep saying what it said after the employee gets a
 * rise, changes department or leaves. A line that read those back through the
 * employee record would rewrite every payslip ever issued the moment HR edited
 * anything — the same class of bug as an invoice that re-quotes its own tax.
 *
 * `gross_cents`, `deductions_cents` and `net_cents` are stored too, which this
 * codebase otherwise refuses to do. Same argument, and it is the exception
 * worth making: those three are what somebody was handed on paper.
 * `PayrollService` is the only thing that writes them.
 */
class PayRunLine extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'pay_run_id', 'employee_id', 'employee_no', 'name', 'position',
        'pay_basis', 'days_worked', 'sheet_days', 'trips',
        'basic_cents', 'allowance_cents', 'overtime_cents',
        'adjustments_cents', 'adjustment_note',
        'component_earnings_cents', 'component_deductions_cents',
        'sss_cents', 'philhealth_cents', 'pagibig_cents',
        'sss_enrolled', 'philhealth_enrolled', 'pagibig_enrolled',
        'withholding_tax_cents', 'other_deductions_cents', 'deduction_note',
        'store_deduction_cents',
        'gross_cents', 'deductions_cents', 'net_cents',
    ];

    protected function casts(): array
    {
        return [
            /**
             * How this payslip was worked out — frozen, like the figures.
             *
             * A person moved from a day rate to a salary must not have last
             * March's payslip start describing itself as a monthly one.
             */
            'pay_basis' => PayBasis::class,
            'days_worked' => 'integer',
            'sheet_days' => 'integer',
            'trips' => 'integer',
            'basic_cents' => 'integer',
            'allowance_cents' => 'integer',
            'overtime_cents' => 'integer',
            'adjustments_cents' => 'integer',
            'component_earnings_cents' => 'integer',
            'component_deductions_cents' => 'integer',
            'sss_cents' => 'integer',
            'philhealth_cents' => 'integer',
            'pagibig_cents' => 'integer',
            'withholding_tax_cents' => 'integer',
            'other_deductions_cents' => 'integer',
            'store_deduction_cents' => 'integer',
            // Which contributions this payslip was subject to, as it was on
            // the day. A person enrolled in SSS next month must not have this
            // month's payslip start claiming they were.
            'sss_enrolled' => 'boolean',
            'philhealth_enrolled' => 'boolean',
            'pagibig_enrolled' => 'boolean',
            'gross_cents' => 'integer',
            'deductions_cents' => 'integer',
            'net_cents' => 'integer',
        ];
    }

    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    /**
     * The firm's own allowances and deductions, itemised.
     *
     * What the payslip actually *says* — `component_earnings_cents` and
     * `component_deductions_cents` are only what it adds up. Frozen copies, so
     * this relation is safe to read years later; see `PayRunLineComponent`.
     */
    public function components(): HasMany
    {
        return $this->hasMany(PayRunLineComponent::class, 'pay_run_line_id');
    }

    /**
     * The person this pays.
     *
     * Kept for the link — a payslip should open the employee, and next period's
     * run reads their salary from here — but nothing on this row is read
     * through it. See the note at the top.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Earnings, before anything comes off.
     *
     * Adjustments are inside the gross because that is where a bonus belongs:
     * it is pay. A deduction dressed up as a negative adjustment lands here
     * too, which is why the note beside it is worth filling in.
     */
    public function computedGrossCents(): int
    {
        return $this->basic_cents
            + $this->allowance_cents
            + $this->overtime_cents
            + $this->adjustments_cents
            + $this->component_earnings_cents;
    }

    /** Everything coming off, whoever it goes to. */
    public function computedDeductionsCents(): int
    {
        return $this->sss_cents
            + $this->philhealth_cents
            + $this->pagibig_cents
            + $this->withholding_tax_cents
            + $this->other_deductions_cents
            + $this->component_deductions_cents
            + $this->store_deduction_cents;
    }

    /**
     * Why is this payslip zero?
     *
     * Null when it is not, and a sentence when it is. Worth stating rather
     * than leaving somebody to work out, because a ₱0.00 line on a run is
     * almost always one of three things and they need different fixing:
     *
     *   nothing in the period names this person — no sheet rows, no trips,
     *   the period found their work but their contract carries no rate,
     *   they have no contract with a figure on it at all.
     *
     * Read entirely off the frozen columns, so an old payslip keeps explaining
     * itself the way it did on the day — the same rule as every other figure
     * on this row.
     */
    public function zeroExplanation(): ?string
    {
        if ($this->gross_cents > 0) {
            return null;
        }

        $basis = $this->pay_basis ?? PayBasis::Monthly;

        if (! $basis->countsWork()) {
            return 'No salary is set on this person’s contract.';
        }

        if ($basis === PayBasis::PerTrip) {
            return $this->trips === 0
                ? 'No hauls were delivered by this person between these two dates. Per-trip pay is '
                    .'the contract rate times the trips delivered in the period, so a run they were '
                    .'not out on pays nothing.'
                : 'The period has '.$this->trips.' delivered trip(s) for this person, but their '
                    .'contract has no trip rate on it. Write them a contract with a rate and work '
                    .'the run out again.';
        }

        if ($this->sheet_days === 0) {
            return 'No truck-sheet rows name this person between these two dates. Daily pay is '
                .'counted from the daily sheet, so a run they were not written onto pays nothing. '
                .'Put them on the day’s row as driver or helper, then work the run out again.';
        }

        return 'The sheet has '.$this->sheet_days.' day(s) for this person, but their daily rate is '
            .'zero. Write them a contract with a rate and work the run out again.';
    }

    /** What the person is actually handed. */
    public function computedNetCents(): int
    {
        return $this->computedGrossCents() - $this->computedDeductionsCents();
    }

    /**
     * Do the stored totals still match their parts?
     *
     * They always should — `PayrollService` writes all three together — and a
     * mismatch means something wrote to this table directly. Worth being able
     * to ask, because the three stored figures are the ones on the paper.
     */
    public function isConsistent(): bool
    {
        return $this->gross_cents === $this->computedGrossCents()
            && $this->deductions_cents === $this->computedDeductionsCents()
            && $this->net_cents === $this->computedNetCents();
    }

    /**
     * Do the two component totals match the rows they claim to add up?
     *
     * A separate question from `isConsistent()`, because it is a different kind
     * of wrong: that one asks whether the payslip's totals match its own
     * columns, and this asks whether the columns match the itemised lines the
     * payslip prints beside them. A payslip listing a ₱1,000 rice allowance and
     * adding ₱2,000 to the gross is arithmetically fine and still indefensible
     * to the person holding it.
     *
     * Requires the relation to be loaded; an unloaded one loads it.
     */
    public function componentTotalsAgree(): bool
    {
        $rows = $this->components;

        $earnings = (int) $rows->where('kind', PayComponentKind::Earning)->sum('amount_cents');
        $deductions = (int) $rows->where('kind', PayComponentKind::Deduction)->sum('amount_cents');

        return $this->component_earnings_cents === $earnings
            && $this->component_deductions_cents === $deductions;
    }
}
