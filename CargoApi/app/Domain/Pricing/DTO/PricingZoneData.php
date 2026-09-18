<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;

/**
 * A zone as the editor sends it — the band and its rate lines in one payload.
 *
 * `brackets` is deliberately absent from `toArray()`. It is not a column, and
 * leaving it there would have `persistable()` hand the repository a key no
 * table has. `PricingZoneService` reads it off the property instead and syncs
 * the rows, which is the only sensible shape for an editor where somebody adds
 * a line, retypes another and drags a third away before pressing save once.
 *
 * `aliases` is gone along with the column. A zone is a band of kilometres now,
 * and the town names that used to sit here priced nothing the day the matcher
 * was removed — a field that still accepted them would be a field the office
 * could not tell had stopped mattering.
 */
final class PricingZoneData extends Data
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $code = null,
        public readonly ?int $min_km = null,
        public readonly ?int $max_km = null,
        public readonly ?int $diesel_baseline_cents = null,
        public readonly ?int $position = null,
        public readonly ?StatusValue $status = null,
        public readonly ?string $notes = null,
        /** @var array<int, array<string, mixed>>|null */
        public readonly ?array $brackets = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            name: $attributes['name'] ?? null,
            /**
             * Upper-cased rather than lower, which is the one place this DTO
             * changed its mind.
             *
             * A zone code used to be a slug because it was a place — `davao-
             * city`. It is now a cell of a printed table, and that table says
             * `A1`, `E2`, `O`. Storing `a1` would have every screen either
             * show a code the office cannot find on the sheet in front of them
             * or re-case it on the way out, and the second is the kind of
             * fix-up that gets applied in three places and forgotten in the
             * fourth.
             */
            code: isset($attributes['code']) ? mb_strtoupper(trim((string) $attributes['code'])) : null,
            min_km: isset($attributes['min_km']) ? (int) $attributes['min_km'] : null,
            max_km: array_key_exists('max_km', $attributes) && $attributes['max_km'] !== null
                ? (int) $attributes['max_km']
                : null,
            diesel_baseline_cents: isset($attributes['diesel_baseline_cents'])
                ? (int) $attributes['diesel_baseline_cents']
                : null,
            position: isset($attributes['position']) ? (int) $attributes['position'] : null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
            notes: $attributes['notes'] ?? null,
            brackets: isset($attributes['brackets']) ? (array) $attributes['brackets'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'code' => $this->code,
            'min_km' => $this->min_km,
            'max_km' => $this->max_km,
            'diesel_baseline_cents' => $this->diesel_baseline_cents,
            'position' => $this->position,
            'status' => $this->status?->value,
            'notes' => $this->notes,
        ];
    }
}
