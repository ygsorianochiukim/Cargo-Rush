<?php

declare(strict_types=1);

namespace App\Domain\Hr\Requests;

use App\Domain\Hr\DTO\EmployeeData;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class EmployeeRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();
        $employee = $this->route('employee');

        return [
            // Optional: allocated by the service when the office has none to
            // give, which is the normal case.
            'employee_no' => [
                'sometimes', 'string', 'max:30',
                Rule::unique('employees', 'employee_no')->ignore($employee?->id)->whereNull('deleted_at'),
            ],
            'first_name' => [$required, 'string', 'max:60'],
            'last_name' => [$required, 'string', 'max:60'],
            'middle_name' => ['nullable', 'string', 'max:60'],
            /**
             * Either a title from the managed list or one typed in.
             *
             * On a create one of the two is required; on a PATCH neither is,
             * so an edit that resends only a corrected surname does not demand
             * the person be reclassified as well.
             */
            'position' => [
                $this->creating() ? 'required_without:position_id' : 'sometimes',
                'string',
                'max:60',
            ],
            'position_id' => ['nullable', 'string', 'exists:positions,id'],
            'department' => ['nullable', 'string', 'max:60'],
            'employment_type' => ['sometimes', Rule::in(EmploymentType::values())],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
            'hired_on' => [$required, 'date'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'contact' => [$required, 'string', 'max:40'],
            // Not unique and not required. Plenty of staff have no address of
            // their own, and it is not the login — creating an account is a
            // separate action that validates the address it is given.
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:80'],
            'emergency_phone' => ['nullable', 'string', 'max:40'],
            'base_salary_cents' => ['sometimes', 'integer', 'min:0'],
            // One driver, one employee — the column is unique, and a clear
            // message here beats a 500 from the database.
            'driver_id' => [
                'nullable', 'string', 'exists:drivers,id',
                Rule::unique('employees', 'driver_id')->ignore($employee?->id)->whereNull('deleted_at'),
            ],
            'notes' => ['nullable', 'string', 'max:255'],
            'photo' => [
                'nullable', 'image',
                'max:'.(int) config('cargo.hr.photo_max_kb'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'driver_id.unique' => 'That driver is already linked to another employee record.',
            'base_salary_cents.integer' => 'Send the salary in centavos as a whole number, not pesos.',
            'photo.max' => 'That photograph is too large. An ID photo, not a portrait session.',
        ];
    }

    public function toData(): EmployeeData
    {
        // The file is handled by `PhotoStore`, not by a column, so it is kept
        // out of the DTO entirely.
        return EmployeeData::fromArray(collect($this->validated())->except('photo')->all());
    }

    public function photo(): ?UploadedFile
    {
        $photo = $this->file('photo');

        return $photo instanceof UploadedFile ? $photo : null;
    }
}
