<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;

/**
 * A zone as the editor sends it — the zone row and its whole card in one
 * payload.
 *
 * `brackets` is deliberately absent from `toArray()`. It is not a column, and
 * leaving it there would have `persistable()` hand the repository a key no
 * table has. `PricingZoneService` reads it off the property instead and syncs
 * the rows, which is the only sensible shape for an editor where somebody adds
 * a bracket, retypes another and drags a third away before pressing save once.
 */
final class PricingZoneData extends Data
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $code = null,
        /** @var string[]|null */
        public readonly ?array $aliases = null,
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
            code: isset($attributes['code']) ? mb_strtolower(trim((string) $attributes['code'])) : null,
            aliases: isset($attributes['aliases']) ? self::cleanAliases($attributes['aliases']) : null,
            diesel_baseline_cents: isset($attributes['diesel_baseline_cents'])
                ? (int) $attributes['diesel_baseline_cents']
                : null,
            position: isset($attributes['position']) ? (int) $attributes['position'] : null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
            notes: $attributes['notes'] ?? null,
            brackets: isset($attributes['brackets']) ? (array) $attributes['brackets'] : null,
        );
    }

    /**
     * Trimmed, blanks dropped, duplicates removed.
     *
     * An editor that lets somebody type a list will collect empty rows and
     * accidental repeats, and every one of those costs a string comparison on
     * every zone for every quote.
     *
     * @return string[]
     */
    private static function cleanAliases(mixed $aliases): array
    {
        $cleaned = array_map(
            static fn ($alias): string => trim((string) $alias),
            (array) $aliases,
        );

        return array_values(array_unique(array_filter(
            $cleaned,
            static fn (string $alias): bool => $alias !== '',
        )));
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'code' => $this->code,
            'aliases' => $this->aliases,
            'diesel_baseline_cents' => $this->diesel_baseline_cents,
            'position' => $this->position,
            'status' => $this->status?->value,
            'notes' => $this->notes,
        ];
    }
}
