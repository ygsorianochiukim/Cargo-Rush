<?php

declare(strict_types=1);

namespace App\Domain\Hr\Requests;

use App\Domain\Shared\Enums\LeaveType;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class LeaveRequestRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'employee_id' => [$required, 'string', 'exists:employees,id'],
            'type' => [$required, Rule::in(LeaveType::values())],
            'starts_on' => [$required, 'date'],
            'ends_on' => [$required, 'date', 'after_or_equal:starts_on'],
            'reason' => [$required, 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => 'Leave cannot end before it starts.',
            'reason.required' => 'Say what the leave is for — it is what the approver reads.',
        ];
    }
}
