<?php

declare(strict_types=1);

namespace App\Domain\Hr\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\StatusValue;

/**
 * The photograph is deliberately not here.
 *
 * It arrives as an uploaded file, not as a column value, and the path it turns
 * into is decided by `PhotoStore` after the file is written. Carrying it
 * through the DTO would mean either a `UploadedFile` in something whose whole
 * job is to be a flat set of column values, or a path invented before the file
 * exists.
 */
final class EmployeeData extends Data
{
    public function __construct(
        public readonly ?string $employee_no = null,
        public readonly ?string $first_name = null,
        public readonly ?string $last_name = null,
        public readonly ?string $middle_name = null,
        public readonly ?string $position = null,
        /** The managed job title, when one was chosen from the list. */
        public readonly ?string $position_id = null,
        public readonly ?string $department = null,
        public readonly ?EmploymentType $employment_type = null,
        public readonly ?StatusValue $status = null,
        public readonly ?string $hired_on = null,
        public readonly ?string $birth_date = null,
        public readonly ?string $contact = null,
        public readonly ?string $email = null,
        public readonly ?string $address = null,
        public readonly ?string $emergency_contact = null,
        public readonly ?string $emergency_phone = null,
        public readonly ?int $base_salary_cents = null,
        public readonly ?string $driver_id = null,
        public readonly ?string $notes = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        return new self(
            employee_no: $attributes['employee_no'] ?? null,
            first_name: $attributes['first_name'] ?? null,
            last_name: $attributes['last_name'] ?? null,
            middle_name: $attributes['middle_name'] ?? null,
            position: $attributes['position'] ?? null,
            position_id: $attributes['position_id'] ?? null,
            department: $attributes['department'] ?? null,
            employment_type: isset($attributes['employment_type'])
                ? EmploymentType::from($attributes['employment_type'])
                : null,
            status: isset($attributes['status']) ? StatusValue::from($attributes['status']) : null,
            hired_on: $attributes['hired_on'] ?? null,
            birth_date: $attributes['birth_date'] ?? null,
            contact: $attributes['contact'] ?? null,
            email: $attributes['email'] ?? null,
            address: $attributes['address'] ?? null,
            emergency_contact: $attributes['emergency_contact'] ?? null,
            emergency_phone: $attributes['emergency_phone'] ?? null,
            base_salary_cents: isset($attributes['base_salary_cents'])
                ? (int) $attributes['base_salary_cents']
                : null,
            driver_id: $attributes['driver_id'] ?? null,
            notes: $attributes['notes'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'employee_no' => $this->employee_no,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'middle_name' => $this->middle_name,
            'position' => $this->position,
            'position_id' => $this->position_id,
            'department' => $this->department,
            'employment_type' => $this->employment_type?->value,
            'status' => $this->status?->value,
            'hired_on' => $this->hired_on,
            'birth_date' => $this->birth_date,
            'contact' => $this->contact,
            'email' => $this->email,
            'address' => $this->address,
            'emergency_contact' => $this->emergency_contact,
            'emergency_phone' => $this->emergency_phone,
            'base_salary_cents' => $this->base_salary_cents,
            'driver_id' => $this->driver_id,
            'notes' => $this->notes,
        ];
    }
}
