<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A service area on the rate card.
 *
 * The zone holds no price of its own — the brackets do. It exists to answer
 * one question about a booking ("which card applies to this destination?") and
 * to give the office something to name and reorder.
 */
class PricingZone extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'aliases', 'diesel_baseline_cents',
        'position', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'diesel_baseline_cents' => 'integer',
            'position' => 'integer',
            'status' => StatusValue::class,
        ];
    }

    public function brackets(): HasMany
    {
        return $this->hasMany(PricingBracket::class, 'zone_id')->orderBy('min_km');
    }

    /**
     * Every string this zone answers to, lower-cased.
     *
     * The name is included so a zone with no aliases still matches on what it
     * is called — which is the state every zone is in the moment it is created.
     *
     * @return string[]
     */
    public function matchTerms(): array
    {
        $terms = array_merge([$this->name, $this->code], $this->aliases ?? []);

        return array_values(array_unique(array_filter(
            array_map(static fn ($term): string => mb_strtolower(trim((string) $term)), $terms),
            static fn (string $term): bool => $term !== '',
        )));
    }

    /** The bracket a run of this many kilometres falls in, or null. */
    public function bracketFor(int $km): ?PricingBracket
    {
        return $this->brackets
            ->first(fn (PricingBracket $bracket): bool => $bracket->covers($km));
    }

    public function isActive(): bool
    {
        return $this->status === StatusValue::Active;
    }
}
