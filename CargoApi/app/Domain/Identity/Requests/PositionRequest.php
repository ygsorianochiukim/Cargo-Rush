<?php

declare(strict_types=1);

namespace App\Domain\Identity\Requests;

use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class PositionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'name' => [$required, 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:160'],
            /**
             * Does somebody in this job need a `drivers` record?
             *
             * Which is the same question as *do they use the handset*. It used
             * to be inferred from the position's default role, which read
             * correctly right up to the firm that gave its mechanics a driver
             * login to move units around the yard — so the office says it
             * outright now.
             */
            'drives' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],

            /**
             * The rate card: one basis, and a figure for each of the three
             * tiers somebody moves through.
             *
             * A **default at the moment of hire**. Hiring into this job opens a
             * contract and copies the tier's figure onto it; payroll reads the
             * contract and never looks here again — so editing these changes
             * what the next hire is offered and nothing about anybody already
             * on the roster.
             *
             * All optional, and zero means "no rate set": a position nobody has
             * priced opens no contract and leaves the hire form for the office
             * to fill in, which is what every position on the roster does
             * today. Contractual and part-time hires are paid from the regular
             * figure, which is why there are three of these rather than five.
             */
            'pay_basis' => ['sometimes', Rule::enum(PayBasis::class)],
            'trainee_amount_cents' => ['sometimes', 'integer', 'min:0'],
            'probationary_amount_cents' => ['sometimes', 'integer', 'min:0'],
            'regular_amount_cents' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'trainee_amount_cents.integer' => 'Send the trainee rate in centavos as a whole number, not pesos.',
            'probationary_amount_cents.integer' => 'Send the probationary rate in centavos as a whole number, not pesos.',
            'regular_amount_cents.integer' => 'Send the regular rate in centavos as a whole number, not pesos.',
        ];
    }
}
