<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StoreCreditKind;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One row of somebody's store tab — the *pautang* ledger.
 *
 * A charge is goods taken against pay; a payment is money back, whether off a
 * payslip or handed over at the counter. The balance is the difference, and
 * that is deliberately all it is: a tab is settled in part far more often than
 * in full, and a model that marked charges paid would have to decide which
 * ₱500 of the rice and the cooking oil a cutoff had settled — an order nobody
 * agreed and the system would have to invent.
 *
 * ## Why not a pay component
 *
 * `pay_components` already expresses a standing deduction, and a cash-advance
 * repayment is exactly what it is for. It is the wrong shape for a tab. A
 * component is an instruction with an amount and a date range, settled by
 * somebody remembering to end it; a tab has no amount until the cutoff, because
 * it changes every time the storekeeper writes a line.
 */
class StoreCredit extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'employee_id', 'kind', 'amount_cents', 'description',
        'outlet', 'charged_on', 'pay_run_line_id', 'recorded_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'kind' => StoreCreditKind::class,
            'amount_cents' => 'integer',
            'charged_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The payslip that took it, on a row payroll wrote.
     *
     * Null on a charge, and on a repayment somebody made in cash at the
     * counter. Kept for the link back — "which cutoff paid this off?" is the
     * first question anybody asks of a settled tab.
     */
    public function payRunLine(): BelongsTo
    {
        return $this->belongsTo(PayRunLine::class, 'pay_run_line_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** What this row does to the balance, signed. */
    public function signedCents(): int
    {
        return $this->kind->sign() * $this->amount_cents;
    }

    public function isCharge(): bool
    {
        return $this->kind === StoreCreditKind::Charge;
    }

    /**
     * Was this written by payroll rather than by the storekeeper?
     *
     * Which decides whether it can be edited: a repayment taken off an
     * approved payslip is part of that payslip, and moving it would leave the
     * tab and the payslip disagreeing with nothing to say which is right.
     */
    public function isFromPayroll(): bool
    {
        return $this->pay_run_line_id !== null;
    }

    /** Rows that count toward the balance on a date, newest first. */
    public function scopeUpTo(Builder $query, string $date): Builder
    {
        return $query->whereDate('charged_on', '<=', $date);
    }

    public function scopeForEmployee(Builder $query, string $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    /** The tab as it reads: most recent first, and stable within a day. */
    public function scopeInLedgerOrder(Builder $query): Builder
    {
        return $query->orderByDesc('charged_on')->orderByDesc('created_at')->orderByDesc('id');
    }
}
