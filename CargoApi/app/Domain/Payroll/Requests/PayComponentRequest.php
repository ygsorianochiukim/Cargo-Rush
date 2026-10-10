<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Requests;

use App\Domain\Shared\Enums\PayComponentBasis;
use App\Domain\Shared\Enums\PayComponentKind;
use App\Domain\Shared\Enums\PayComponentSchedule;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PayComponentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();
        $component = $this->route('component');

        return [
            'name' => [
                $required, 'string', 'max:60',
                Rule::unique('pay_components', 'name')
                    ->where('company_id', app(Tenant::class)->id())
                    ->ignore($component?->id)
                    ->whereNull('deleted_at'),
            ],

            'kind' => [$required, Rule::enum(PayComponentKind::class)],
            'basis' => ['sometimes', Rule::enum(PayComponentBasis::class)],

            'amount_cents' => ['sometimes', 'integer', 'min:0'],
            'rate_bp' => ['sometimes', 'integer', 'min:0', 'max:10000'],

            'schedule' => ['sometimes', Rule::enum(PayComponentSchedule::class)],
            'taxable' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $basis = $this->basisInForce();

            if ($basis === PayComponentBasis::Fixed && $this->resolvedAmount() <= 0) {
                $validator->errors()->add('amount_cents', 'A fixed component needs an amount. Use a percentage basis to set it as a share of the basic.');
            }

            if ($basis === PayComponentBasis::PercentOfBasic && $this->resolvedRate() <= 0) {
                $validator->errors()->add('rate_bp', 'A percentage component needs a rate. 450 is 4.5% of the monthly basic.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        $attributes = $this->safe()->only([
            'name', 'kind', 'basis', 'amount_cents', 'rate_bp',
            'schedule', 'taxable', 'status', 'position', 'notes',
        ]);

        if (array_key_exists('taxable', $attributes)) {
            $attributes['taxable'] = (bool) $attributes['taxable'];
        }

        return $attributes;
    }

    private function basisInForce(): PayComponentBasis
    {
        if ($this->has('basis')) {
            return PayComponentBasis::tryFrom((string) $this->input('basis')) ?? PayComponentBasis::Fixed;
        }

        $component = $this->route('component');

        return $component?->basis ?? PayComponentBasis::Fixed;
    }

    private function resolvedAmount(): int
    {
        return $this->has('amount_cents')
            ? (int) $this->input('amount_cents')
            : (int) ($this->route('component')?->amount_cents ?? 0);
    }

    private function resolvedRate(): int
    {
        return $this->has('rate_bp')
            ? (int) $this->input('rate_bp')
            : (int) ($this->route('component')?->rate_bp ?? 0);
    }
}
