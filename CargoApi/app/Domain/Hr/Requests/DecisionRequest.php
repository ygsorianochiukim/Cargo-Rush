<?php

declare(strict_types=1);

namespace App\Domain\Hr\Requests;

use App\Domain\Shared\Enums\RequestStatus;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class DecisionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                Rule::in([RequestStatus::Approved->value, RequestStatus::Rejected->value]),
            ],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.in' => 'A decision is either approved or rejected.',
        ];
    }

    public function decision(): RequestStatus
    {
        return RequestStatus::from((string) $this->input('decision'));
    }
}
