<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereHas('line.payRun', static fn (Builder $run) => $run
            ->whereIn('status', [PayRun::APPROVED, PayRun::PAID]));
    }
}
