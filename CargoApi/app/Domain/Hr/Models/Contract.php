<?php

declare(strict_types=1);

namespace App\Domain\Hr\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * What one person is paid, from a given day.
 *
 * The answer to "what is she on", and — because these are rows rather than a
 * column — to "what was she on last year" and "when did that change".
 *
 * A raise is a new row, never an edit. That is the whole design: it means
 * raising one person cannot touch anybody else, cannot touch the position they
 * hold, and cannot restate a payslip that has already been issued. The old row
 * stays exactly as it was, which is what makes last March's run still rebuild
 * to last March's figure.
 *
 * ## The amount means what the basis says
 *
 * One `amount_cents`, not three columns. Monthly is a salary for the month,
 * daily is a rate for a day worked, per trip is a rate for a haul delivered —
 * and payroll multiplies the last two by what the period actually holds. Three
 * columns would have been three places for two of them to be wrong.
 */
class Contract extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'employee_id', 'pay_basis', 'tier', 'amount_cents', 'effective_from',
        'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'pay_basis' => PayBasis::class,
            /**
             * Which column of the rate card this figure came from.
             *
             * Always one of the three `EmploymentType::tiers()` — a contractual
             * or part-time hire is written down as `regular`, because that is
             * the figure they were actually paid from. See
             * `EmploymentType::tier()`.
             */
            'tier' => EmploymentType::class,
            'amount_cents' => 'integer',
            'effective_from' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Who wrote it down. Null for the rows the migration opened. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The agreements that have started by a given day, newest first.
     *
     * The ordering is the definition of "in force": `effective_from` descending
     * picks the most recent one to have arrived, and `created_at` breaks a tie
     * between two rows dated the same day — which means somebody corrected the
     * first, and the correction wins.
     *
     * Future rows are excluded rather than ignored. A rise dated the first of
     * next month is a real record that simply is not paying yet, and a run
     * rebuilt for last fortnight must not pick it up.
     */
    public function scopeInForceOn(Builder $query, Carbon|string|null $on = null): Builder
    {
        $date = $on === null ? Carbon::now() : Carbon::parse($on);

        return $query->whereDate('effective_from', '<=', $date->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at');
    }

    /** `₱15,000 a month` — the figure and what it buys, in one phrase. */
    public function summary(): string
    {
        $basis = $this->pay_basis ?? PayBasis::Monthly;
        $cents = (int) $this->amount_cents;

        if ($cents <= 0) {
            return 'No rate set yet.';
        }

        return sprintf(
            '₱%s %s',
            number_format($cents / 100, $cents % 100 === 0 ? 0 : 2),
            $basis->unit(),
        );
    }
}
