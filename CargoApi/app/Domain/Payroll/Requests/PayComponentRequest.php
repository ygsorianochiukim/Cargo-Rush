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

/**
 * A component of the firm's salary structure.
 *
 * The amount fields are both `sometimes` and neither is `required`, which looks
 * lax and is not: which one matters depends on the basis, so the rule that
 * actually enforces it is in `withValidator()` below, where it can say the
 * useful thing — "a percentage component needs a rate" rather than "rate_bp is
 * required" on a form where the rate field is hidden.
 */
class PayComponentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();
        $component = $this->route('component');

        return [
            'name' => [
                $required, 'string', 'max:60',
                // Per company, unlike most of this codebase's uniqueness rules:
                // "Rice allowance" is a name two hauliers will both use, and
                // the index on the table is on the pair.
                Rule::unique('pay_components', 'name')
                    ->where('company_id', app(Tenant::class)->id())
                    ->ignore($component?->id)
                    ->whereNull('deleted_at'),
            ],

            'kind' => [$required, Rule::enum(PayComponentKind::class)],
            'basis' => ['sometimes', Rule::enum(PayComponentBasis::class)],

            // Unsigned, both of them. A deduction is a positive number that
            // comes off — see `PayComponentKind` — so there is no such thing
            // here as a negative amount, and allowing one would produce a
            // deduction that paid somebody.
            'amount_cents' => ['sometimes', 'integer', 'min:0'],
            // 10,000 basis points is 100% of the basic. More than that is not a
            // component, it is a typo with a payroll run behind it.
            'rate_bp' => ['sometimes', 'integer', 'min:0', 'max:10000'],

            'schedule' => ['sometimes', Rule::enum(PayComponentSchedule::class)],
            'taxable' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The amount has to match the basis.
     *
     * A fixed component with no amount and a percentage one with no rate are
     * both saveable rows that pay nothing, and neither reports itself: they
     * simply resolve to zero, get dropped from every payslip as an empty line,
     * and look for all the world like a component nobody was assigned. Caught
     * here, where the message can name the field the form is actually showing.
     */
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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * The basis this save will end up with — the one sent, or the one the row
     * already has.
     *
     * A PATCH that changes only the rate has to be checked against the basis
     * already stored, or every such edit would be judged against the default.
     */
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
