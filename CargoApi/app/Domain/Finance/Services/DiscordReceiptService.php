<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\DTO\ExpenseData;
use App\Domain\Finance\Models\DiscordExpenseReceipt;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Shared\Enums\PaySchedule;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DiscordReceiptService
{
    /** @return array<string, string> */
    public function categories(): array
    {
        return ExpenseCategory::query()->where('status', StatusValue::Active->value)
            ->orderBy('position')->pluck('name', 'key')->all();
    }

    public function update(DiscordExpenseReceipt $receipt, array $fields): void
    {
        $receipt->update([
            'history' => [...$receipt->history, [
                'revision' => $receipt->revision,
                'fields' => $receipt->fields,
                'replaced_at' => now()->toIso8601String(),
            ]],
            'revision' => $receipt->revision + 1,
            'fields' => $fields,
        ]);
    }

    public function expense(DiscordExpenseReceipt $receipt): ExpenseData
    {
        $fields = $this->validator($receipt)->validate();
        $category = ExpenseCategory::query()
            ->where('status', StatusValue::Active->value)->where('key', $fields['category'])->firstOrFail();
        [$pesos, $centavos] = array_pad(explode('.', $fields['amount'], 2), 2, '');

        return ExpenseData::fromArray([
            ...$fields,
            'category_id' => $category->getKey(),
            'amount_cents' => (int) $pesos * 100 + (int) str_pad($centavos, 2, '0'),
            'is_vatable' => $fields['vat'] === 'yes',
            'status' => StatusValue::Active->value,
        ]);
    }

    public function preview(DiscordExpenseReceipt $receipt, bool $mayConfirm): array
    {
        return [
            'id' => $receipt->id,
            'status' => $receipt->status,
            'revision' => $receipt->revision,
            'filename' => $receipt->filename,
            'fields' => $receipt->fields,
            'issues' => $receipt->status === 'pending' ? $this->validator($receipt)->errors()->all() : [],
            'categories' => (object) $this->categories(),
            'expense_id' => $receipt->expense_id,
            'may_confirm' => $mayConfirm,
        ];
    }

    private function validator(DiscordExpenseReceipt $receipt): \Illuminate\Validation\Validator
    {
        $inCompany = static fn (string $table) => Rule::exists($table, 'id')
            ->where('company_id', $receipt->company_id)->whereNull('deleted_at');

        return Validator::make($receipt->fields, [
            'date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/', 'numeric', 'gt:0'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'category' => ['required', Rule::exists('expense_categories', 'key')
                ->where('company_id', $receipt->company_id)->where('status', StatusValue::Active->value)->whereNull('deleted_at')],
            'vat' => ['required', Rule::in(['yes', 'no'])],
            'payee' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', $inCompany('suppliers')],
            'trip_id' => ['nullable', $inCompany('trips')],
            'payment_source_id' => ['nullable', $inCompany('payment_sources')],
            'pay_schedule' => ['required', Rule::in(PaySchedule::values())],
        ], [
            'amount.regex' => 'Enter the total as a decimal amount without a currency symbol.',
            'category.exists' => 'Choose a category key from the available Other Expenses categories.',
            'vat.required' => 'Set VAT to yes or no after checking the receipt.',
        ])->after(static function (\Illuminate\Validation\Validator $validator) use ($receipt): void {
            if (DiscordExpenseReceipt::query()->where('posted_fingerprint', $receipt->fingerprint)->exists()) {
                $validator->errors()->add('receipt', 'This exact receipt image was already recorded.');
            }
        });
    }
}
