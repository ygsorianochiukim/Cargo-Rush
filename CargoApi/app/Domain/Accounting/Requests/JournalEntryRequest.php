<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Requests;

use App\Domain\Accounting\DTO\JournalEntryData;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Shared\Enums\BalanceSide;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class JournalEntryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'entry_date' => [$required, 'date'],

            'category' => [$required, Rule::in(JournalCategory::values())],

            'memo' => [$required, 'string', 'max:255'],

            'status' => ['sometimes', Rule::in([JournalEntry::DRAFT, JournalEntry::POSTED])],

            'lines' => [$required, 'array', 'min:2', 'max:100'],

            'lines.*.account_id' => [
                'required', 'string', 'size:26',
                function (string $attribute, mixed $value, callable $fail): void {
                    $account = Account::query()->find($value);

                    if ($account === null) {
                        $fail('That account is not in your chart of accounts.');

                        return;
                    }

                    if (! $account->isActive()) {
                        $fail(sprintf('%s is retired and cannot take new postings.', $account->label()));
                    }
                },
            ],

            'lines.*.side' => ['required', Rule::in([BalanceSide::Debit->value, BalanceSide::Credit->value])],

            'lines.*.amount_cents' => ['required', 'integer', 'min:1'],

            'lines.*.memo' => ['nullable', 'string', 'max:255'],

            'lines.*.truck_id' => ['nullable', 'string', 'size:26'],
            'lines.*.trip_id' => ['nullable', 'string', 'size:26'],
            'lines.*.customer_id' => ['nullable', 'string', 'size:26'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'A journal entry needs its two sides.',
            'lines.min' => 'A journal entry needs at least two lines — what was debited, and what was credited.',
            'lines.*.amount_cents.min' => 'A line has to be for some amount.',
            'memo.required' => 'Say what the entry is for.',
            'category.required' => 'Choose what kind of transaction this is.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $payload = $validator->getData();

            if (! is_array($payload['lines'] ?? null)) {
                return;
            }

            $data = JournalEntryData::fromArray($payload);

            if ($data->isBalanced()) {
                return;
            }

            $validator->errors()->add('lines', sprintf(
                'The two sides do not agree: debits are ₱%s and credits are ₱%s, a difference of ₱%s.',
                number_format($data->totalDebits() / 100, 2),
                number_format($data->totalCredits() / 100, 2),
                number_format(abs($data->difference()) / 100, 2),
            ));
        });
    }

    public function toData(): JournalEntryData
    {
        return JournalEntryData::fromArray($this->validated());
    }
}
