<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Requests;

use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Shared\Http\Requests\ApiFormRequest;

class WalletEntryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'in:'.implode(',', [
                WalletEntryKind::Payout->value,
                WalletEntryKind::Remittance->value,
                WalletEntryKind::Adjustment->value,
            ])],

            'entry_ids' => ['sometimes', 'array'],
            'entry_ids.*' => ['string', 'max:26'],

            'amount_cents' => ['sometimes', 'integer', 'not_in:0'],

            'method' => ['sometimes', 'string', 'max:40'],

            'cleared' => ['sometimes', 'boolean'],

            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
            'occurred_on' => ['nullable', 'date'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $adjustment = $this->input('kind') === WalletEntryKind::Adjustment->value;

            if ($adjustment) {
                if (trim((string) $this->input('note')) === '') {
                    $validator->errors()->add('note', 'Say what this adjustment is for.');
                }

                if ($this->input('amount_cents') === null) {
                    $validator->errors()->add('amount_cents', 'Enter the amount to adjust by.');
                }

                return;
            }

            if ($this->input('amount_cents') !== null) {
                $validator->errors()->add(
                    'amount_cents',
                    'A payout is made up of the runs it covers. Pick the runs rather than typing a figure.',
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'amount_cents.not_in' => 'Enter an amount.',
        ];
    }

    /** @return string[] */
    public function entryIds(): array
    {
        return array_values(array_filter(
            array_map(trim(...), (array) $this->input('entry_ids', [])),
            static fn (string $id): bool => $id !== '',
        ));
    }
}
