<?php

declare(strict_types=1);

namespace App\Domain\Gps\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use Database\Factories\GpsPingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GpsPing extends Model
{
    /** @use HasFactory<GpsPingFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

    protected $fillable = [
        'trip_id', 'location', 'lat', 'lng', 'speed_kph', 'heading',
        'progress_pct', 'distance_done_m', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
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

    public function isPlotted(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    public function scopePlotted(Builder $query): Builder
    {
        return $query->whereNotNull('lat')->whereNotNull('lng');
    }

    /** @return array{lat: float, lng: float}|null */
    public static function coordinatesFromLocation(?string $location): ?array
    {
        if ($location === null) {
            return null;
        }

        if (! preg_match('/^\s*(-?\d{1,3}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)\s*$/', $location, $matches)) {
            return null;
        }

        $lat = (float) $matches[1];
        $lng = (float) $matches[2];

        if (abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }
}
