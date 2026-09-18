<?php

declare(strict_types=1);

namespace App\Domain\Hr\Requests;

use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * A new agreement for one person.
 *
 * Only the figure is required. Everything else carries over from the agreement
 * in force, because the everyday case is a rise — a change of number and
 * nothing else — and making an office restate the basis and the tier on every
 * one is how a basis eventually gets restated wrong.
 */
class ContractRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'amount_cents' => ['required', 'integer', 'min:0'],

            /**
             * Monthly, daily or per trip. Carried over when absent.
             *
             * Stating it is how somebody moves between them — a driver going
             * from a monthly salary onto a trip rate — which is a different
             * conversation from a rise and is written down as one.
             */
            'pay_basis' => ['sometimes', Rule::enum(PayBasis::class)],

            /**
             * Which column of the rate card this came from. Carried over when
             * absent, and set outright on a regularisation.
             */
            'tier' => ['sometimes', Rule::in(array_column(EmploymentType::tiers(), 'value'))],

            /**
             * The day it starts paying. Today unless stated.
             *
             * Future dates are allowed and are half the point: next month's
             * rise is written now and sits there until it arrives. Past dates
             * are allowed too, for the correction to a figure that was always
             * wrong and has to reach the run somebody is checking.
             */
            'effective_from' => ['sometimes', 'date'],

            /** Why, in the office's own words. "Annual increase". */
            'reason' => ['sometimes', 'nullable', 'string', 'max:160'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount_cents.integer' => 'Send the pay in centavos as a whole number, not pesos.',
        ];
    }
}
