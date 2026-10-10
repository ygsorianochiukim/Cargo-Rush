<?php

declare(strict_types=1);

namespace App\Domain\Shared\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

abstract class ApiResource extends JsonResource
{
    public static $wrap = 'data';

    protected function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d\TH:i:s\Z')
            : (string) $value;
    }

    /** @return array<string, string|null> */
    protected function stamps(): array
    {
        return [
            'created_at' => $this->iso($this->resource->created_at ?? null),
            'updated_at' => $this->iso($this->resource->updated_at ?? null),
        ];
    }
}
