<?php

declare(strict_types=1);

namespace App\Domain\Customer\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\StatusValue;

final class CustomerData extends Data
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $contact = null,
        public readonly ?float $rating = null,
        public readonly ?StatusValue $status = null,
        /**
         * The address the firm signs in with.
         *
         * Deliberately absent from `toArray()`: there is no `email` column on
         * `customers`, because the login is a `users` row — the same split the
         * driver has, where the account and the business record are two
         * things. `CustomerService` reads this to make that account, and left
         * empty it falls back to the contact when the contact is an address.
         */
        public readonly ?string $email = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            name: $attributes['name'] ?? null,
            contact: $attributes['contact'] ?? null,
            rating: isset($attributes['rating']) ? (float) $attributes['rating'] : null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
            email: $attributes['email'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'contact' => $this->contact,
            'rating' => $this->rating,
            'status' => $this->status?->value,
        ];
    }
}
