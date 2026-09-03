<?php

declare(strict_types=1);

namespace App\Domain\Hr\Requests;

use App\Domain\Shared\Enums\RequestStatus;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Approving or rejecting a request.
 *
 * Only those two: `pending` is where a request starts rather than somewhere it
 * can be sent back to, and `cancelled` is the employee withdrawing, which is a
 * different action by a different person.
 */
class DecisionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                Rule::in([RequestStatus::Approved->value, RequestStatus::Rejected->value]),
            ],
            // Optional everywhere, including on a rejection. A note somebody is
            // forced to write gets written as a full stop.
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
