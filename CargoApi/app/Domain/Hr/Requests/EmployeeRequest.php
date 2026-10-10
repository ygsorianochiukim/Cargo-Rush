<?php

declare(strict_types=1);

namespace App\Domain\Hr\Requests;

use App\Domain\Driver\Models\Driver;
use App\Domain\Hr\DTO\EmployeeData;
use App\Domain\Hr\DTO\LicenceData;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\Position;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EmployeeRequest extends ApiFormRequest
{
    public const RELIGIONS = [
        'Roman Catholic',
        'Iglesia ni Cristo',
        'Islam',
        'Evangelical Christian',
        'Born Again Christian',
        'Aglipayan',
        'Seventh-day Adventist',
        'Baptist',
        'Jehovah’s Witness',
        'Latter-day Saints',
        'Buddhism',
        'Other',
        'Prefer not to say',
    ];

    public function rules(): array
    {
        $required = $this->requiredOnCreate();
        $employee = $this->employee();

        return [
            'employee_no' => [
                'sometimes', 'string', 'max:30',
                Rule::unique('employees', 'employee_no')->ignore($employee?->id)->whereNull('deleted_at'),
            ],
            'first_name' => [$required, 'string', 'max:60'],
            'last_name' => [$required, 'string', 'max:60'],
            'middle_name' => ['nullable', 'string', 'max:60'],
            'position' => [
                $this->creating() ? 'required_without:position_id' : 'sometimes',
                'string',
                'max:60',
            ],
            'position_id' => [
                'nullable', 'string',
                Rule::exists('positions', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
            'department' => ['nullable', 'string', 'max:60'],
            'employment_type' => ['sometimes', Rule::in(EmploymentType::values())],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
            'hired_on' => [$required, 'date'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'contact' => [$required, 'string', 'max:40'],
            'blood_type' => ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'religion' => ['nullable', Rule::in(self::RELIGIONS)],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:80'],
            'emergency_phone' => ['nullable', 'string', 'max:40'],
            'pay_basis' => ['sometimes', Rule::enum(PayBasis::class)],
            'amount_cents' => ['sometimes', 'integer', 'min:0'],
            'effective_from' => ['sometimes', 'date'],

            'sss_enrolled' => ['sometimes', 'boolean'],
            'philhealth_enrolled' => ['sometimes', 'boolean'],
            'pagibig_enrolled' => ['sometimes', 'boolean'],

            'licence_no' => [$this->licenceRequired() ? 'required' : 'nullable', 'string', 'max:40'],
            'licence_expiry' => [$this->licenceRequired() ? 'required' : 'nullable', 'date'],

            'notes' => ['nullable', 'string', 'max:255'],
            'photo' => [
                'nullable', 'image',
                'max:'.(int) config('cargo.hr.photo_max_kb'),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $licence = $this->string('licence_no')->trim()->value();

                if ($licence === '' || $validator->errors()->has('licence_no')) {
                    return;
                }

                $driverId = Driver::query()->where('licence_no', $licence)->value('id');

                if ($driverId === null) {
                    return;
                }

                $heldBy = Employee::query()
                    ->where('driver_id', $driverId)
                    ->whereKeyNot($this->employee()?->id)
                    ->value('employee_no');

                if ($heldBy !== null) {
                    $validator->errors()->add(
                        'licence_no',
                        "That licence is already on employee {$heldBy}'s record.",
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'licence_no.required' => 'A driver needs their licence number.',
            'licence_expiry.required' => 'A driver needs their licence expiry date.',
            'amount_cents.integer' => 'Send the pay in centavos as a whole number, not pesos.',
            'photo.max' => 'That photograph is too large. An ID photo, not a portrait session.',
        ];
    }

    public function toData(): EmployeeData
    {
        return EmployeeData::fromArray(
            collect($this->validated())->except(['photo', 'licence_no', 'licence_expiry'])->all()
        );
    }

    public function toLicence(): ?LicenceData
    {
        $licence = collect($this->validated())->only(['licence_no', 'licence_expiry'])->all();

        return $licence === [] ? null : LicenceData::fromArray($licence);
    }

    public function photo(): ?UploadedFile
    {
        $photo = $this->file('photo');

        return $photo instanceof UploadedFile ? $photo : null;
    }

    private function licenceRequired(): bool
    {
        return $this->jobPosition()?->drives === true
            && $this->employee()?->driver_id === null;
    }

    private function jobPosition(): ?Position
    {
        $positionId = $this->input('position_id') ?: $this->employee()?->position_id;

        return $positionId === null ? null : Position::query()->find($positionId);
    }

    private function employee(): ?Employee
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee ? $employee : null;
    }
}
