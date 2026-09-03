<?php

declare(strict_types=1);

namespace App\Domain\Hr\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/** Leaving before the shift is out — a slice of one day, not a day off. */
class UndertimeRequest extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'employee_id', 'date', 'from_time', 'to_time', 'hours', 'reason',
        'status', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'hours' => 'float',
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
     * The hours between two times of day, to two decimals.
     *
     * Both times are on the same date by construction, so this cannot span
     * midnight — which is exactly why the column is a `time` and not a
     * timestamp. A request that appears to run backwards is a data-entry slip
     * and is refused by the form rather than silently becoming a negative.
     */
    public static function hoursBetween(string $from, string $to): float
    {
        $start = Carbon::createFromFormat('H:i', substr($from, 0, 5));
        $end = Carbon::createFromFormat('H:i', substr($to, 0, 5));

        if ($start === false || $end === false || $end->lessThanOrEqualTo($start)) {
            return 0.0;
        }

        return round($start->diffInMinutes($end) / 60, 2);
    }

    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('status', RequestStatus::Approved->value);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', RequestStatus::Pending->value);
    }
}
