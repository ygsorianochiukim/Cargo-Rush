<?php

declare(strict_types=1);

namespace App\Domain\Hr\Resources;

use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Services\PhotoStore;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin Employee */
class EmployeeResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $contract = $this->contractOn();

        return [
            'id' => $this->id,
            'employee_no' => $this->employee_no,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'middle_name' => $this->middle_name,
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
            'blood_type' => $this->blood_type,
            'religion' => $this->religion,
            'email' => $this->email,
            'address' => $this->address,
            'emergency_contact' => $this->emergency_contact,
            'emergency_phone' => $this->emergency_phone,
            'pay_basis' => $contract?->pay_basis?->value,
            'pay_basis_label' => $contract?->pay_basis?->label(),
            'pay_basis_detail' => $contract?->pay_basis?->detail(),
            'amount_cents' => (int) ($contract?->amount_cents ?? 0),
            'pay_summary' => $contract?->summary(),
            'contract_id' => $contract?->getKey(),
            'contract_effective_from' => $contract?->effective_from?->toDateString(),
            'has_contract' => $contract !== null,

            'sss_enrolled' => (bool) $this->sss_enrolled,
            'philhealth_enrolled' => (bool) $this->philhealth_enrolled,
            'pagibig_enrolled' => (bool) $this->pagibig_enrolled,
            'has_statutory_exemption' => $this->hasStatutoryExemption(),

            'paid_per_unit_worked' => $this->paidPerUnitWorked(),
            'photo_url' => app(PhotoStore::class)->url($this->photo_path),
            'position_drives' => (bool) ($this->jobPosition?->drives ?? false),
            'licence_no' => $this->driver?->licence_no,
            'licence_expiry' => $this->driver?->licence_expiry?->toDateString(),

            'driver_id' => $this->driver_id,
            'driver_name' => $this->driver?->name,
            'user_id' => $this->user_id,
            'account_email' => $this->user?->email,
            'has_account' => $this->user_id !== null,
            'role' => $this->user?->role,
            'role_label' => $this->user?->roleLabel(),
            'notes' => $this->notes,

            ...$this->stamps(),
        ];
    }
}
