<?php

declare(strict_types=1);

namespace App\Domain\Hr\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\StatusValue;

final class EmployeeData extends Data
{
    public function __construct(
        public readonly ?string $employee_no = null,
        public readonly ?string $first_name = null,
        public readonly ?string $last_name = null,
        public readonly ?string $middle_name = null,
        public readonly ?string $position = null,
        public readonly ?string $position_id = null,
        public readonly ?string $department = null,
        public readonly ?EmploymentType $employment_type = null,
        public readonly ?StatusValue $status = null,
        public readonly ?string $hired_on = null,
        public readonly ?string $birth_date = null,
        public readonly ?string $contact = null,
        public readonly ?string $blood_type = null,
        public readonly ?string $religion = null,
        public readonly ?string $email = null,
        public readonly ?string $address = null,
        public readonly ?string $emergency_contact = null,
        public readonly ?string $emergency_phone = null,
        public readonly ?PayBasis $pay_basis = null,
        public readonly ?int $amount_cents = null,
        public readonly ?string $effective_from = null,
        public readonly ?bool $sss_enrolled = null,
        public readonly ?bool $philhealth_enrolled = null,
        public readonly ?bool $pagibig_enrolled = null,
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
            blood_type: $attributes['blood_type'] ?? null,
            religion: $attributes['religion'] ?? null,
            email: $attributes['email'] ?? null,
            address: $attributes['address'] ?? null,
            emergency_contact: $attributes['emergency_contact'] ?? null,
            emergency_phone: $attributes['emergency_phone'] ?? null,
            pay_basis: isset($attributes['pay_basis'])
                ? PayBasis::from($attributes['pay_basis'])
                : null,
            amount_cents: isset($attributes['amount_cents'])
                ? (int) $attributes['amount_cents']
                : null,
            effective_from: $attributes['effective_from'] ?? null,
            sss_enrolled: isset($attributes['sss_enrolled'])
                ? (bool) $attributes['sss_enrolled']
                : null,
            philhealth_enrolled: isset($attributes['philhealth_enrolled'])
                ? (bool) $attributes['philhealth_enrolled']
                : null,
            pagibig_enrolled: isset($attributes['pagibig_enrolled'])
                ? (bool) $attributes['pagibig_enrolled']
                : null,
            notes: $attributes['notes'] ?? null,
        );
    }

    /** @return array{pay_basis: ?PayBasis, amount_cents: ?int, effective_from: ?string}|null */
    public function contractTerms(): ?array
    {
        if ($this->pay_basis === null && $this->amount_cents === null) {
            return null;
        }

        return [
            'pay_basis' => $this->pay_basis,
            'amount_cents' => $this->amount_cents,
            'effective_from' => $this->effective_from,
        ];
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
            'blood_type' => $this->blood_type,
            'religion' => $this->religion,
            'email' => $this->email,
            'address' => $this->address,
            'emergency_contact' => $this->emergency_contact,
            'emergency_phone' => $this->emergency_phone,
            'sss_enrolled' => $this->sss_enrolled,
            'philhealth_enrolled' => $this->philhealth_enrolled,
            'pagibig_enrolled' => $this->pagibig_enrolled,
            'notes' => $this->notes,
        ];
    }
}
