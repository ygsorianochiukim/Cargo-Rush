<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Services\PayrollService;
use App\Domain\Payroll\Support\PayPeriod;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One payroll period, and everybody paid on it.
 *
 * ## Three states, and two of them are one-way
 *
 * A **draft** can be recalculated from scratch — that is what it is for. The
 * office builds it, looks at it, adds an adjustment, builds it again.
 *
 * **Approved** freezes it. Somebody has looked at the figures and said yes, and
 * from that moment a payslip can be handed over — which means the figures must
 * stop moving. This is the same argument a posted journal entry makes, for the
 * same reason: a document that can be quietly restated after it has been given
 * to somebody is not a record of anything.
 *
 * **Paid** records that the money went out, and is what posts the run to the
 * books: salaries to expense, the statutory deductions to their payables, the
 * net to cash. Nothing before that touches the ledger, because nothing before
 * that has happened.
 */
class PayRun extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    /** Work in progress. Recalculable, editable, deletable. */
    public const DRAFT = 'draft';

    /** Signed off. The figures are frozen and payslips can go out. */
    public const APPROVED = 'approved';

    /** The money has gone out, and the books have it. */
    public const PAID = 'paid';

    protected $fillable = [
        'reference', 'period_start', 'period_end', 'pay_date', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'pay_date' => 'date',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayRunLine::class)->orderBy('name');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** The entry this run posted, once it was paid. */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    /** Past changing: approved or paid. */
    public function isLocked(): bool
    {
        return ! $this->isDraft();
    }

    public function grossCents(): int
    {
        return (int) $this->lines->sum('gross_cents');
    }

    public function deductionsCents(): int
    {
        return (int) $this->lines->sum('deductions_cents');
    }

    public function netCents(): int
    {
        return (int) $this->lines->sum('net_cents');
    }

    /**
     * What each agency is owed out of this run.
     *
     * Kept apart rather than as one figure, because each is remitted on its own
     * form to its own agency — and the month's remittance is the sum of these
     * across runs, which a single total could not answer.
     *
     * @return array<string, int>
     */
    public function statutoryCents(): array
    {
        return [
            'sss' => (int) $this->lines->sum('sss_cents'),
            'philhealth' => (int) $this->lines->sum('philhealth_cents'),
            'pagibig' => (int) $this->lines->sum('pagibig_cents'),
            'withholding_tax' => (int) $this->lines->sum('withholding_tax_cents'),
            /**
             * Everything the **firm** is recovering, rather than remitting.
             *
             * Three columns, not one, and the two that were missing were a real
             * bug rather than a tidy-up. The journal a paid run writes debits
             * the whole gross and credits the agencies, this bucket and the
             * net — so the entry only balances if this is every deduction that
             * is not a contribution or the tax.
             *
             * It was `other_deductions_cents` alone. A run carrying any pay
             * component deduction — a uniform, a cash-advance repayment, the
             * things that table exists for — therefore came out short by that
             * amount and `JournalService` refused to post it, with a balance
             * error naming no cause. Nothing tested paying a run that had one.
             *
             * `store_deduction_cents` joins them for the same reason: the money
             * never leaves the bank, so it has to be credited somewhere, and
             * what the firm is recovering is exactly what this bucket is.
             */
            'other' => (int) $this->lines->sum('other_deductions_cents')
                + (int) $this->lines->sum('component_deductions_cents')
                + (int) $this->lines->sum('store_deduction_cents'),
        ];
    }

    /**
     * Is this the month's first payslip?
     *
     * Which cutoff a run is decides how much of a monthly salary and how much
     * of a monthly contribution it carries — see `PayrollCalendar::classify()`,
     * which is the one place that answers it.
     *
     * Named for the position in the month rather than for a pair of dates,
     * because the dates are now the firm's own: "the 1st-to-15th payslip" is
     * true of most hauliers here and not of one cutting off on the 10th and the
     * 25th, while "the first of the month's two" is true of both.
     */
    public function isFirstCutoff(): bool
    {
        return $this->cutoff()['first'];
    }

    /** True where payroll runs once a month, so this run is the whole of it. */
    public function isOnlyRunOfMonth(): bool
    {
        return $this->cutoff()['only'];
    }

    /** Which run of the month this is, from zero. */
    public function cutoffIndex(): int
    {
        return $this->cutoff()['index'];
    }

    /** How many runs the month this run belongs to has. */
    public function cutoffCount(): int
    {
        return $this->cutoff()['count'];
    }

    /**
     * @return array{first: bool, only: bool, index: int, count: int}
     */
    private function cutoff(): array
    {
        if ($this->period_start === null || $this->period_end === null) {
            return ['first' => true, 'only' => false, 'index' => 0, 'count' => 1];
        }

        // Through the service rather than resolving a company here: a model
        // reaching for the tenant itself is one more place that could disagree
        // about whose calendar is in force.
        return app(PayrollService::class)->calendar()->classify($this->period_start, $this->period_end);
    }

    /**
     * How the period reads on a list: `1–15 Sep 2026`.
     *
     * Formatted by `PayPeriod`, which is what produced the period this run was
     * opened on. A second copy of the format here is how a register heading
     * comes to disagree with the button that made it — and once a period can
     * cross a month boundary, the two would disagree visibly.
     */
    public function periodLabel(): string
    {
        if ($this->period_start === null || $this->period_end === null) {
            return '';
        }

        return PayPeriod::formatRange($this->period_start, $this->period_end);
    }

    public function scopeInPayrollOrder(Builder $query): Builder
    {
        return $query->orderByDesc('period_start')->orderByDesc('reference');
    }

    /** Same rule as a trip or an invoice: the reference is the system's. */
    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->reference ??= static::nextReference();
        });
    }

    /**
     * The next reference in the PR-YYYY-#### series.
     *
     * Numbered within the year, like an invoice, because payroll is filed and
     * reported by the year it belongs to. `withTrashed` so a deleted draft does
     * not hand its number to the next run — a reference on a payslip has been
     * quoted to somebody.
     */
    public static function nextReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = "PR-{$year}-";

        $last = static::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $n = $last === null ? 0 : (int) substr((string) $last, strlen($prefix));

        return $prefix.str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }
}
