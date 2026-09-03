<?php

declare(strict_types=1);

namespace App\Domain\Hr\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\LeaveType;
use App\Domain\Shared\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/** A request to be off, and the decision on it. */
class LeaveRequest extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'employee_id', 'type', 'starts_on', 'ends_on', 'days', 'reason',
        'status', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'days' => 'float',
            'type' => LeaveType::class,
            'status' => RequestStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * How many days a range covers, inclusive of both ends.
     *
     * Inclusive because a one-day leave is entered with the same date twice,
     * and a difference would make that zero days off. Weekends are not
     * excluded: this system has no shift calendar to know which days somebody
     * was rostered for, and quietly guessing would be worse than counting
     * plainly — the office can enter two requests around a rest day.
     */
    public static function daysBetween(Carbon $from, Carbon $to): float
    {
        return (float) ($from->diffInDays($to) + 1);
    }

    /** Off the road: an approved request, whatever its type. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('status', RequestStatus::Approved->value);
    }

    /** Still on somebody's desk. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', RequestStatus::Pending->value);
    }

    /** Requests overlapping a range — nobody can be on two leaves at once. */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('starts_on', '<=', $to)
            ->whereDate('ends_on', '>=', $from);
    }

    /** Is this leave in force today? Drives the "away right now" count. */
    public function coversToday(): bool
    {
        $today = Carbon::now()->startOfDay();

        return $this->status === RequestStatus::Approved
            && $this->starts_on?->lessThanOrEqualTo($today)
            && $this->ends_on?->greaterThanOrEqualTo($today);
    }
}
