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
            // The role somebody in this job normally gets, so the account form
            // can pre-select it instead of asking twice.
            'suggested_role' => $this->jobPosition?->defaultRole?->key,
            'suggested_role_name' => $this->jobPosition?->defaultRole?->name,
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
            'base_salary_cents' => $this->base_salary_cents,
            // Resolved on read, never stored: moving the install must not
            // orphan every photograph on the roster.
            'photo_url' => app(PhotoStore::class)->url($this->photo_path),
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
