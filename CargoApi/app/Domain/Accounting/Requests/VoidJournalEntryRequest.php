<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;

class VoidJournalEntryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the entry is being voided — it stays in the books with the reason on it.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated()['reason']);
    }
}
