<?php

declare(strict_types=1);

namespace App\Domain\Hr\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\LeaveType;
use App\Domain\Shared\Enums\RequestStatus;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class LeaveRequest extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

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

    public static function daysBetween(Carbon $from, Carbon $to): float
    {
        return (float) ($from->diffInDays($to) + 1);
    }

    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('status', RequestStatus::Approved->value);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', RequestStatus::Pending->value);
    }

    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('starts_on', '<=', $to)
            ->whereDate('ends_on', '>=', $from);
    }

    public function coversToday(): bool
    {
        $today = Carbon::now()->startOfDay();

        return $this->status === RequestStatus::Approved
            && $this->starts_on?->lessThanOrEqualTo($today)
            && $this->ends_on?->greaterThanOrEqualTo($today);
    }
}
