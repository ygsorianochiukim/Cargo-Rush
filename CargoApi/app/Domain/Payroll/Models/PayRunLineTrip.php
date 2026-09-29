<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One haul on one payslip.
 *
 * On a draft it is a reservation, rewritten on every rebuild. On an approved or
 * paid run it is the record that this person has been paid for this trip —
 * which is what the next run reads to find the ones still owed, and what stops
 * a trip being paid twice. See the migration that adds the table.
 */
class PayRunLineTrip extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = ['pay_run_line_id', 'trip_id', 'employee_id'];

    public function line(): BelongsTo
    {
        return $this->belongsTo(PayRunLine::class, 'pay_run_line_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * Rows on a run whose figures are frozen — approved or paid, and not a
     * deleted draft. These are the trips that have been paid.
     */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereHas('line.payRun', static fn (Builder $run) => $run
            ->whereIn('status', [PayRun::APPROVED, PayRun::PAID]));
    }
}
