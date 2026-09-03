<?php

declare(strict_types=1);

namespace App\Domain\Hr\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\ApplicantStage;

final class ApplicantData extends Data
{
    public function __construct(
        public readonly ?string $first_name = null,
        public readonly ?string $last_name = null,
        public readonly ?string $position_applied = null,
        public readonly ?string $contact = null,
        public readonly ?string $email = null,
        public readonly ?string $address = null,
        public readonly ?string $source = null,
        public readonly ?string $applied_on = null,
        public readonly ?ApplicantStage $stage = null,
        public readonly ?int $rating = null,
        public readonly ?string $notes = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            first_name: $attributes['first_name'] ?? null,
            last_name: $attributes['last_name'] ?? null,
            position_applied: $attributes['position_applied'] ?? null,
            contact: $attributes['contact'] ?? null,
            email: $attributes['email'] ?? null,
            address: $attributes['address'] ?? null,
            source: $attributes['source'] ?? null,
            applied_on: $attributes['applied_on'] ?? null,
            stage: isset($attributes['stage']) ? ApplicantStage::from($attributes['stage']) : null,
            rating: isset($attributes['rating']) ? (int) $attributes['rating'] : null,
            notes: $attributes['notes'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'position_applied' => $this->position_applied,
            'contact' => $this->contact,
            'email' => $this->email,
            'address' => $this->address,
            'source' => $this->source,
            'applied_on' => $this->applied_on,
            'stage' => $this->stage?->value,
            'rating' => $this->rating,
            'notes' => $this->notes,
        ];
    }
}
