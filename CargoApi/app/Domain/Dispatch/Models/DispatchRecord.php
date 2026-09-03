<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Models;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Factories\DispatchRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** When and where a unit left, and when it got there. */
class DispatchRecord extends Model
{
    /** @use HasFactory<DispatchRecordFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'trip_id', 'vehicle_id', 'dispatched_at', 'location', 'arrived_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'arrived_at' => 'datetime',
            'status' => StatusValue::class,
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
