<?php

declare(strict_types=1);

namespace App\Domain\Shared\DTO;

/**
 * Base for every domain DTO.
 *
 * A DTO is the only thing that crosses the Controller -> Service -> Repository
 * boundary. Controllers never hand a Service an array or a Request, and a
 * Service never hands a Repository one either, so a change to a form field
 * shows up as a type error rather than a silently ignored key.
 *
 * The one thing a plain typed object cannot express is the difference between
 * "the caller did not mention this field" and "the caller set it to null" —
 * and on a PATCH those mean opposite things. So the key list the caller
 * actually sent travels alongside the values, and `persistable()` uses it.
 */
abstract class Data
{
    /**
     * The keys present in the payload this was built from.
     *
     * @var string[]
     */
    protected array $provided = [];

    /**
     * Build from a validated request payload. Subclasses implement this; the
     * public entry point is `fromArray()`, which also records the key list.
     *
     * @param  array<string, mixed>  $attributes
     */
    abstract protected static function hydrate(array $attributes): static;

    /**
     * The full shape, keys named for their columns.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * @param  array<string, mixed>  $attributes
     */
    final public static function fromArray(array $attributes): static
    {
        return static::hydrate($attributes)->withProvided(array_keys($attributes));
    }

    /**
     * Did the caller actually mention this field?
     *
     * The same distinction `persistable()` rests on, asked about one key. A
     * service sometimes needs it before writing: a trip's price is derived
     * from the tariff *unless somebody typed one*, and "they typed zero" and
     * "they said nothing" are opposite instructions that a null cannot tell
     * apart.
     */
    final public function wasGiven(string $key): bool
    {
        return in_array($key, $this->provided, true);
    }

    /**
     * What to actually write.
     *
     * Only the fields the caller sent. On a create that lets the column
     * defaults stand instead of overwriting them with nulls the caller never
     * asked for; on a PATCH it means sending `helper_id: null` really does
     * clear the helper, while leaving it out leaves it alone.
     *
     * @return array<string, mixed>
     */
    public function persistable(): array
    {
        $all = $this->toArray();

        if ($this->provided === []) {
            return array_filter($all, static fn ($value) => $value !== null);
        }

        return array_intersect_key($all, array_flip($this->provided));
    }

    /**
     * @param  string[]  $keys
     */
    protected function withProvided(array $keys): static
    {
        $clone = clone $this;
        $clone->provided = $keys;

        return $clone;
    }
}
