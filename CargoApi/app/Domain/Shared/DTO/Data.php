<?php

declare(strict_types=1);

namespace App\Domain\Shared\DTO;

abstract class Data
{
    /** @var string[] */
    protected array $provided = [];

    /** @param array<string, mixed> $attributes */
    abstract protected static function hydrate(array $attributes): static;

    /** @return array<string, mixed> */
    abstract public function toArray(): array;

    /** @param array<string, mixed> $attributes */
    final public static function fromArray(array $attributes): static
    {
        return static::hydrate($attributes)->withProvided(array_keys($attributes));
    }

    final public function wasGiven(string $key): bool
    {
        return in_array($key, $this->provided, true);
    }

    /** @return array<string, mixed> */
    public function persistable(): array
    {
        $all = $this->toArray();

        if ($this->provided === []) {
            return array_filter($all, static fn ($value) => $value !== null);
        }

        return array_intersect_key($all, array_flip($this->provided));
    }

    /** @param string[] $keys */
    protected function withProvided(array $keys): static
    {
        $clone = clone $this;
        $clone->provided = $keys;

        return $clone;
    }
}
