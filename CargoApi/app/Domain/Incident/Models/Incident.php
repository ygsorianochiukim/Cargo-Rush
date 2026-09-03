<?php

declare(strict_types=1);

namespace App\Domain\Incident\Models;

use App\Domain\Driver\Models\Driver;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Something went wrong, with a time and a place. */
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'reference', 'kind', 'place', 'occurred_at',
        'driver_id', 'vehicle_id', 'trip_id', 'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'status' => StatusValue::class,
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** Same rule as a trip: the reference is assigned before the insert. */
    protected static function booted(): void
    {
        static::creating(function (self $incident): void {
            $incident->reference ??= static::nextReference();
        });
    }

    /** The next reference in the INC-#### series. */
    public static function nextReference(): string
    {
        $last = static::withTrashed()
            ->where('reference', 'like', 'INC-%')
            ->orderByDesc('reference')
            ->value('reference');

        $n = $last === null ? 200 : (int) substr((string) $last, 4);

        return 'INC-'.str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }
}
