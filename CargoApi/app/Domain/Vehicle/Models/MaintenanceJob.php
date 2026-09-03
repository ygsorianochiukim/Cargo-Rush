<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Models;

use App\Domain\Shared\Enums\StatusValue;
use Database\Factories\MaintenanceJobFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An assigned service job. The driver app lists these under Inspect. */
class MaintenanceJob extends Model
{
    /** @use HasFactory<MaintenanceJobFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = ['vehicle_id', 'kind', 'due_at', 'next_service_km', 'status'];

    protected function casts(): array
    {
        return [
            'due_at' => 'date',
            'next_service_km' => 'integer',
            'status' => StatusValue::class,
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
