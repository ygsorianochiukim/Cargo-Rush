<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Models;

use App\Domain\Driver\Models\Driver;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleDriverAssignment extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = ['vehicle_id', 'driver_id', 'from_date', 'to_date'];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('to_date');
    }

    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query
            ->whereDate('from_date', '<=', $to->toDateString())
            ->where(static fn (Builder $open) => $open
                ->whereNull('to_date')
                ->orWhereDate('to_date', '>=', $from->toDateString()));
    }

    public function scopeOnHiredTrucks(Builder $query): Builder
    {
        return $query->whereHas('vehicle', static fn (Builder $vehicle) => $vehicle
            ->withTrashed()
            ->whereNotNull('arrangement')
            ->where('arrangement', '!=', 'owned'));
    }

    public function covers(CarbonInterface $day): bool
    {
        return $this->from_date->lte($day->copy()->startOfDay())
            && ($this->to_date === null || $this->to_date->gte($day->copy()->startOfDay()));
    }
}
