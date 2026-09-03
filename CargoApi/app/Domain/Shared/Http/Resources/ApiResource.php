<?php

declare(strict_types=1);

namespace App\Domain\Shared\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base for every module's Resource.
 *
 * Enforces the two contract rules that are easy to break per-module:
 * timestamps are ISO-8601 UTC and the API never formats a date or a peso.
 */
abstract class ApiResource extends JsonResource
{
    public static $wrap = 'data';

    /** ISO-8601 UTC, or null. DESIGN.md section 7.1. */
    protected function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d\TH:i:s\Z')
            : (string) $value;
    }

    /**
     * The `created_at` / `updated_at` pair every resource exposes.
     *
     * @return array<string, string|null>
     */
    protected function stamps(): array
    {
        return [
            'created_at' => $this->iso($this->resource->created_at ?? null),
            'updated_at' => $this->iso($this->resource->updated_at ?? null),
        ];
    }
}
