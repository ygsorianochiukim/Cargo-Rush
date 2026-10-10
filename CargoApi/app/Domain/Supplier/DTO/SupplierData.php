<?php

declare(strict_types=1);

namespace App\Domain\Supplier\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;

final class SupplierData extends Data
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $category_id = null,
        public readonly ?string $contact = null,
        public readonly ?string $address = null,
        public readonly ?string $supplies = null,
        public readonly ?string $note = null,
        public readonly ?StatusValue $status = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            name: $attributes['name'] ?? null,
            category_id: $attributes['category_id'] ?? null,
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
            'category_id' => $this->category_id,
            'contact' => $this->contact,
            'address' => $this->address,
            'supplies' => $this->supplies,
            'note' => $this->note,
            'status' => $this->status?->value,
        ];
    }
}
