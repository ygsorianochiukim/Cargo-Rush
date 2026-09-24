<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Shared\Enums\DeductionSchedule;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A request to move the firm's pay cutoff.
 *
 * Filed by whoever runs payroll and decided by whoever holds the company
 * settings — see the migration for why those are different people. Approving it
 * **applies** the change; that is the verb, not a note to go and type it in
 * afterwards.
 *
 * ## Three states and a fourth
 *
 * `pending` until somebody decides. `approved` is one-way and is the moment the
 * company's cutoff actually moves. `declined` is the administrator saying no,
 * with a note. `withdrawn` is the asker taking it back, which is deliberately
 * not the same value — a request nobody wanted any more and a request somebody
 * refused are different facts about the same office, and collapsing them loses
 * the one an administrator would want to see a pattern in.
 */
class PayrollCutoffRequest extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    /** Waiting on somebody who can change the setting. */
    public const PENDING = 'pending';

    /** Decided yes — and the cutoff moved when it was. */
    public const APPROVED = 'approved';

    /** Decided no. The note says why. */
    public const DECLINED = 'declined';

    /** Taken back by the person who asked. */
    public const WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'cutoff_days', 'payroll_deduct_on', 'reason', 'status',
        'requested_by', 'decided_by', 'decided_at', 'decision_note',
        'previous_cutoff_days',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_days' => 'array',
            'previous_cutoff_days' => 'array',
            'payroll_deduct_on' => DeductionSchedule::class,
            'decided_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    /** Past deciding — approved, declined or withdrawn. */
    public function isSettled(): bool
    {
        return ! $this->isPending();
    }

    /** The calendar this request is asking for. */
    public function calendar(): PayrollCalendar
    {
        return PayrollCalendar::of((array) $this->cutoff_days);
    }

    /**
     * What this asks for, in a sentence.
     *
     * Built from `PayrollCalendar` rather than written here, so the wording an
     * administrator reads on the approval is the wording the office will see on
     * the settings card afterwards. Two paraphrases of the same setting is how
     * somebody approves a change they did not picture correctly.
     */
    public function summary(): string
    {
        return $this->calendar()->describe();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    /** Newest first: a list of these is read to find the one still waiting. */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }
}
