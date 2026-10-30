<?php

declare(strict_types=1);

namespace App\Domain\Finance\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;

class OwnerCutoffSettleRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
            'method' => ['sometimes', 'string', 'max:40'],
            'cleared' => ['sometimes', 'boolean'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
            'occurred_on' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'start.required' => 'Say which cutoff is being settled.',
            'end.required' => 'Say which cutoff is being settled.',
            'end.after_or_equal' => 'The cutoff cannot end before it starts.',
        ];
    }
}
