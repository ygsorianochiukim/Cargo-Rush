<?php

declare(strict_types=1);

namespace App\Domain\Hr\Resources;

use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Services\PhotoStore;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Employee
 */
class EmployeeResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        // Asked once. The repository eager-loads the contracts, so this is a
        // read of what is already in memory — but reaching for it seven times
        // in the array below would be seven scans, and one of them would
        // eventually be written on a record the relation was not loaded on.
        $contract = $this->contractOn();

        return [
            'id' => $this->id,
            'employee_no' => $this->employee_no,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'middle_name' => $this->middle_name,
            // Composed here so the two clients cannot disagree about how a
            // name is put together.
            'full_name' => $this->fullName(),
            'position' => $this->position,
            'position_id' => $this->position_id,
            'department' => $this->department,
            'employment_type' => $this->employment_type->value,
            'employment_type_label' => $this->employment_type->label(),
            'status' => $this->status->value,
            'hired_on' => $this->hired_on?->toDateString(),
            'birth_date' => $this->birth_date?->toDateString(),
            'contact' => $this->contact,
            'email' => $this->email,
            'address' => $this->address,
            'emergency_contact' => $this->emergency_contact,
            'emergency_phone' => $this->emergency_phone,
            /**
             * What this person is on **today**, read off the contract in force.
             *
             * Flattened onto the record rather than nested, because every
             * screen that asks about an employee's pay wants the current figure
             * and nothing else. The history is its own endpoint for the one
             * screen that shows it.
             *
             * Null and zero where nobody has written a contract yet — a real
             * state, and one that keeps somebody off pay runs rather than
             * putting a ₱0.00 payslip on one.
             *
             * The label and the sentence come from the API so the form offering
             * the choice does not keep its own copy of what "per trip" means.
             */
            'pay_basis' => $contract?->pay_basis?->value,
            'pay_basis_label' => $contract?->pay_basis?->label(),
            'pay_basis_detail' => $contract?->pay_basis?->detail(),
            'amount_cents' => (int) ($contract?->amount_cents ?? 0),
            'pay_summary' => $contract?->summary(),
            'contract_id' => $contract?->getKey(),
            'contract_effective_from' => $contract?->effective_from?->toDateString(),
            'has_contract' => $contract !== null,

            /**
             * Which contributions come off this person's pay.
             *
             * `has_statutory_exemption` is sent alongside so a roster can flag
             * the people who are not on all three without the client
             * re-deriving the rule. It is the sort of thing an office wants to
             * see at a glance before a run, because it is also the sort of
             * thing somebody switches off for a fortnight and forgets.
             */
            'sss_enrolled' => (bool) $this->sss_enrolled,
            'philhealth_enrolled' => (bool) $this->philhealth_enrolled,
            'pagibig_enrolled' => (bool) $this->pagibig_enrolled,
            'has_statutory_exemption' => $this->hasStatutoryExemption(),

            /** The most one payslip takes off the store tab. Zero is all of it. */
            'store_deduction_cap_cents' => (int) $this->store_deduction_cap_cents,
            /**
             * Is this person's pay multiplied by work done in the period?
             *
             * Said plainly rather than left for a client to infer from the
             * basis, because it is what decides whether a screen shows them a
             * salary or a rate — and, on a run, whether the figure came from
             * days on the sheet or hauls delivered.
             */
            'paid_per_unit_worked' => $this->paidPerUnitWorked(),
            // Resolved on read, never stored: moving the install must not
            // orphan every photograph on the roster.
            'photo_url' => app(PhotoStore::class)->url($this->photo_path),
            /**
             * Whether this job asks for a licence, and the licence if it does.
             *
             * `position_drives` is sent so the form knows which fields to show
             * when reopening an existing record, without re-deriving the rule
             * from the position list. It is the same flag `PositionResource`
             * carries, answered for the job this person actually holds.
             *
             * The licence itself is read off the `drivers` row rather than
             * copied onto `employees`. One number, in one place: a renewal
             * recorded in Drivers Management shows on the roster without
             * anything having to keep two columns in step.
             */
            'position_drives' => (bool) ($this->jobPosition?->drives ?? false),
            'licence_no' => $this->driver?->licence_no,
            'licence_expiry' => $this->driver?->licence_expiry?->toDateString(),

            'driver_id' => $this->driver_id,
            'driver_name' => $this->driver?->name,
            'user_id' => $this->user_id,
            'account_email' => $this->user?->email,
            // The chip the roster leads with. Whether somebody can sign in is
            // the question the office is on this screen to answer.
            'has_account' => $this->user_id !== null,
            'role' => $this->user?->role,
            'role_label' => $this->user?->roleLabel(),
            'notes' => $this->notes,

            ...$this->stamps(),
        ];
    }
}
