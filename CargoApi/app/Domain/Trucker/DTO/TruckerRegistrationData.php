<?php

declare(strict_types=1);

namespace App\Domain\Trucker\DTO;

use App\Domain\Shared\DTO\Data;

/**
 * An owner-operator signing themselves up.
 *
 * The third public write, beside a haulier registering and a shipper
 * registering, and the one that asks for the most — because of the three it is
 * the only one where somebody is asking to be handed a stranger's cargo.
 *
 * A shipper's sign-up asks for a name, a number and a password, and that is
 * proportionate: the worst a bad registration there can do is waste a desk's
 * time. This asks for a licence and a truck as well, and neither is a
 * formality. The licence is what the haulier is relying on when it gives them a
 * load; the truck is what decides which loads they can be offered at all, and a
 * partner with no unit on file would sit on the job board accepting work with
 * nothing to haul it in.
 *
 * What it does **not** ask for is which company. A trucker registers with the
 * platform the way a shipper does — see `TruckerRegistrationService` for how
 * the haulier is resolved, and why that is the one thing this feature does
 * differently from the shipper's.
 */
final class TruckerRegistrationData extends Data
{
    public function __construct(
        public readonly string $name = '',
        public readonly string $contact_phone = '',
        public readonly string $email = '',
        public readonly string $password = '',
        public readonly string $licence_no = '',
        public readonly ?string $licence_expiry = null,
        /** Which haulier they are signing up to haul for. */
        public readonly ?string $company_id = null,
        /** Their truck. Registered in the same act — see the class docblock. */
        public readonly string $plate = '',
        public readonly string $model = '',
        public readonly int $capacity_kg = 0,
        public readonly ?string $truck_category_id = null,
        public readonly ?string $device_name = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            name: (string) ($attributes['name'] ?? ''),
            contact_phone: (string) ($attributes['contact_phone'] ?? ''),
            email: (string) ($attributes['email'] ?? ''),
            password: (string) ($attributes['password'] ?? ''),
            licence_no: (string) ($attributes['licence_no'] ?? ''),
            licence_expiry: $attributes['licence_expiry'] ?? null,
            company_id: $attributes['company_id'] ?? null,
            plate: (string) ($attributes['plate'] ?? ''),
            model: (string) ($attributes['model'] ?? ''),
            capacity_kg: (int) ($attributes['capacity_kg'] ?? 0),
            truck_category_id: $attributes['truck_category_id'] ?? null,
            device_name: $attributes['device_name'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'contact_phone' => $this->contact_phone,
            'email' => $this->email,
            'password' => $this->password,
            'licence_no' => $this->licence_no,
            'licence_expiry' => $this->licence_expiry,
            'company_id' => $this->company_id,
            'plate' => $this->plate,
            'model' => $this->model,
            'capacity_kg' => $this->capacity_kg,
            'truck_category_id' => $this->truck_category_id,
            'device_name' => $this->device_name,
        ];
    }

    /**
     * The `users` columns for the login this creates.
     *
     * @return array<string, mixed>
     */
    public function userAttributes(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->contact_phone,
            // Hashed by the model's cast, as every other write of a password
            // in this codebase is.
            'password' => $this->password,
        ];
    }

    /**
     * The `truckers` columns.
     *
     * No `status`: the column defaults to `pending` and this is the one place
     * it would be tempting to set, which is exactly why it is not passed. A
     * registration that could name its own standing is a registration that
     * could vet itself.
     *
     * @return array<string, mixed>
     */
    public function truckerAttributes(): array
    {
        return [
            'name' => $this->name,
            'phone' => $this->contact_phone,
            'licence_no' => $this->licence_no,
            'licence_expiry' => $this->licence_expiry,
        ];
    }

    /**
     * The `trucker_vehicles` columns.
     *
     * @return array<string, mixed>
     */
    public function vehicleAttributes(): array
    {
        return [
            'plate' => $this->plate,
            'model' => $this->model,
            'capacity_kg' => $this->capacity_kg,
            'truck_category_id' => $this->truck_category_id,
        ];
    }

    /** True when the caller wants a bearer token rather than a session cookie. */
    public function wantsToken(): bool
    {
        return $this->device_name !== null && $this->device_name !== '';
    }
}
