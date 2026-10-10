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

class PayrollCutoffRequest extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

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

    public function isSettled(): bool
    {
        return ! $this->isPending();
    }

    public function calendar(): PayrollCalendar
    {
        return PayrollCalendar::of((array) $this->cutoff_days);
    }

    public function summary(): string
    {
        return $this->calendar()->describe();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }
}
