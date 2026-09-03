<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Models;

use App\Domain\Driver\Models\Driver;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Factories\FuelRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One fill-up: the receipt, the odometer reading, and what it cost. */
class FuelRecord extends Model
{
    /** @use HasFactory<FuelRecordFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'vehicle_id', 'driver_id', 'litres', 'amount_cents', 'currency',
        'odometer_km', 'receipt_no', 'logged_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'litres' => 'float',
            'amount_cents' => 'integer',
            'odometer_km' => 'integer',
            'logged_at' => 'datetime',
            'status' => StatusValue::class,
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
