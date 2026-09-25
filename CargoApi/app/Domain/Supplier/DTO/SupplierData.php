<?php

declare(strict_types=1);

namespace App\Domain\Supplier\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;

final class SupplierData extends Data
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $contact = null,
        public readonly ?string $address = null,
        /**
         * What they sell, in the office's own words.
         *
         * Deliberately a sentence rather than a category. A garage that also
         * sells tyres and lends a flatbed is three categories, and making
         * somebody pick one would file the record wrong in a way nobody could
         * correct later. What is actually needed is enough to recognise who to
         * ring, and that is a sentence.
         */
        public readonly ?string $supplies = null,
        public readonly ?string $note = null,
        /** `inactive` is somebody the office has stopped buying from. */
        public readonly ?StatusValue $status = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            name: $attributes['name'] ?? null,
            contact: $attributes['contact'] ?? null,
            address: $attributes['address'] ?? null,
            supplies: $attributes['supplies'] ?? null,
            note: $attributes['note'] ?? null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'contact' => $this->contact,
            'address' => $this->address,
            'supplies' => $this->supplies,
            'note' => $this->note,
            'status' => $this->status?->value,
        ];
    }
}
