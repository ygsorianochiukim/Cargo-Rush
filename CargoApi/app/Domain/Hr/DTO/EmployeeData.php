<?php

declare(strict_types=1);

namespace App\Domain\Hr\DTO;

use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\StatusValue;

/**
 * The photograph is deliberately not here.
 *
 * It arrives as an uploaded file, not as a column value, and the path it turns
 * into is decided by `PhotoStore` after the file is written. Carrying it
 * through the DTO would mean either a `UploadedFile` in something whose whole
 * job is to be a flat set of column values, or a path invented before the file
 * exists.
 *
 * **Neither is `driver_id`, and that one is a change.** It used to be here,
 * filled from a dropdown of every driver on file. It is now decided by
 * `EmployeeService` from the licence number instead — see `LicenceData` — so
 * the client no longer sends it and nothing here should carry it. Leaving the
 * field would mean a payload naming somebody else's driver record was still
 * obeyed, which is the whole thing the change removes.
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
        /**
         * The opening pay, and the only two fields here that are not columns
         * on `employees`.
         *
         * They describe a **contract**, which is its own row with its own date
         * — see `Contract`. The service writes one when both are answered,
         * and falls back to the position's rate card when they are not, which
         * is the usual case: the office picks a job and the figure follows.
         *
         * Null on a PATCH that does not mention them means "do not write a new
         * contract", the same rule every other field here follows. Changing
         * somebody's pay is never a side effect of correcting their phone
         * number.
         */
        public readonly ?PayBasis $pay_basis = null,
        public readonly ?int $amount_cents = null,
        public readonly ?string $effective_from = null,
        /**
         * Which agencies this person is registered with.
         *
         * Null is "not part of this edit", like every other field here — not
         * "no". A PATCH that only corrects a phone number must not quietly
         * stop somebody's SSS.
         */
        public readonly ?bool $sss_enrolled = null,
        public readonly ?bool $philhealth_enrolled = null,
        public readonly ?bool $pagibig_enrolled = null,
        /** The most one payslip may take off the store tab. Zero is all of it. */
        public readonly ?int $store_deduction_cap_cents = null,
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
            store_deduction_cap_cents: isset($attributes['store_deduction_cap_cents'])
                ? (int) $attributes['store_deduction_cap_cents']
                : null,
            notes: $attributes['notes'] ?? null,
        );
    }

    /**
     * The pay the caller stated, or null where they left it to the job.
     *
     * Either half on its own is a real instruction, so either half is enough to
     * write a contract. A figure with no basis is "this much, on whatever this
     * job pays by" — which is the common case, because the basis comes from the
     * job and the office only ever argues about the number. A basis with no
     * figure is "same money, paid differently", which is how somebody moves
     * from a monthly salary onto a trip rate.
     *
     * The service fills in whichever half is missing: from the contract in
     * force, then from the position's rate card. See
     * `EmployeeService::openingContract()`.
     *
     * @return array{pay_basis: ?PayBasis, amount_cents: ?int, effective_from: ?string}|null
     */
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
            'email' => $this->email,
            'address' => $this->address,
            'emergency_contact' => $this->emergency_contact,
            'emergency_phone' => $this->emergency_phone,
            'sss_enrolled' => $this->sss_enrolled,
            'philhealth_enrolled' => $this->philhealth_enrolled,
            'pagibig_enrolled' => $this->pagibig_enrolled,
            'store_deduction_cap_cents' => $this->store_deduction_cap_cents,
            'notes' => $this->notes,
        ];
    }
}
