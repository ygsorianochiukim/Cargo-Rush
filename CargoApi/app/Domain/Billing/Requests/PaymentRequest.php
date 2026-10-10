<?php

declare(strict_types=1);

namespace App\Domain\Billing\Requests;

use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PaymentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'string', 'exists:customers,id'],
            'direction' => ['sometimes', Rule::in(InvoiceDirection::values())],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['sometimes', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:255'],

            'allocations' => ['sometimes', 'array'],
            'allocations.*.invoice_id' => ['required', 'string', 'exists:invoices,id'],
            'allocations.*.amount_cents' => ['required', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $allocations = (array) $this->input('allocations', []);

                if ($allocations === []) {
                    return;
                }

                $allocated = array_sum(array_column($allocations, 'amount_cents'));

                if ($allocated > (int) $this->input('amount_cents')) {
                    $validator->errors()->add(
                        'allocations',
                        'Those add up to more than the payment itself.',
                    );
                }
            },
        ];
    }

    /** @return array<int, array{invoice_id: string, amount_cents: int}> */
    public function allocations(): array
    {
        return array_map(
            static fn (array $row): array => [
                'invoice_id' => (string) $row['invoice_id'],
                'amount_cents' => (int) $row['amount_cents'],
            ],
            (array) ($this->validated()['allocations'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return collect($this->validated())->except('allocations')->all();
    }
}
