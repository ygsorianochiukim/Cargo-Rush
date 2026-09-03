<?php

declare(strict_types=1);

namespace App\Domain\Gps\Models;

use App\Domain\Trip\Models\Trip;
use Database\Factories\GpsPingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One position report from the handset. The driver app writes these; the web
 * GPS Dashboard only ever reads them (DESIGN.md section 5.4).
 */
class GpsPing extends Model
{
    /** @use HasFactory<GpsPingFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'trip_id', 'location', 'speed_kph', 'heading',
        'progress_pct', 'distance_done_m', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'speed_kph' => 'integer',
            'progress_pct' => 'integer',
            'distance_done_m' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
