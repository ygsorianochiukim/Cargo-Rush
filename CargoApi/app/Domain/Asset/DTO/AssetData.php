<?php

declare(strict_types=1);

namespace App\Domain\Asset\DTO;

use App\Domain\Shared\DTO\Data;

final class AssetData extends Data
{
    public function __construct(
        public readonly ?string $category_id = null,
        public readonly ?string $tag = null,
        public readonly ?string $name = null,
        public readonly ?string $serial_no = null,
        public readonly ?string $purchased_on = null,
        public readonly ?int $cost_cents = null,
        public readonly ?string $supplier_id = null,
        public readonly ?string $custodian_id = null,
        public readonly ?string $assigned_on = null,
        public readonly ?string $location = null,
        public readonly ?string $condition = null,
        public readonly ?string $status = null,
        public readonly ?string $note = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            category_id: $attributes['category_id'] ?? null,
            tag: isset($attributes['tag']) ? trim((string) $attributes['tag']) : null,
            name: isset($attributes['name']) ? trim((string) $attributes['name']) : null,
            serial_no: $attributes['serial_no'] ?? null,
            purchased_on: $attributes['purchased_on'] ?? null,
            cost_cents: isset($attributes['cost_cents']) ? (int) $attributes['cost_cents'] : null,
            supplier_id: $attributes['supplier_id'] ?? null,
            custodian_id: $attributes['custodian_id'] ?? null,
            assigned_on: $attributes['assigned_on'] ?? null,
            location: $attributes['location'] ?? null,
            condition: $attributes['condition'] ?? null,
            status: $attributes['status'] ?? null,
            note: $attributes['note'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'category_id' => $this->category_id,
            'tag' => $this->tag === '' ? null : $this->tag,
            'name' => $this->name,
            'serial_no' => $this->serial_no,
            'purchased_on' => $this->purchased_on,
            'cost_cents' => $this->cost_cents,
            'supplier_id' => $this->supplier_id,
            'custodian_id' => $this->custodian_id,
            'assigned_on' => $this->assigned_on,
            'location' => $this->location,
            'condition' => $this->condition,
            'status' => $this->status,
            'note' => $this->note,
        ];
    }
}
