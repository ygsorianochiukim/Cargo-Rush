<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Requests;

use App\Domain\Payroll\Rules\PayrollCutoffDays;
use App\Domain\Shared\Enums\DeductionSchedule;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * What a company may change about itself.
 *
 * A short list, and the omissions are the design. The **name** is on every
 * invoice already issued, the **code** is what makes every other company's
 * uniqueness work, and the **status** is the platform's answer about a firm
 * rather than the firm's about itself. None of the three is a field a form
 * fills in, so none of them is here — rather than being here and quietly
 * dropped by the service.
 *
 * `sometimes` throughout, because this is a PATCH: moving a map pin should not
 * require resending a phone number, and a client that sent only the pin must
 * not blank the rest by omission.
 */
class CompanyProfileRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address' => ['sometimes', 'nullable', 'string', 'max:200'],

            /**
             * Where the yard is.
             *
             * A pair or nothing, the same rule both ends of a trip follow: half
             * a coordinate is not a place, and a latitude stored without its
             * longitude would put the company on the carrier list at a point
             * off the coast of Africa.
             *
             * Both nullable together, which is how a firm takes itself off the
             * list — sending nulls is the only way to stop being discoverable,
             * and it is a deliberate act rather than a flag to find.
             */
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],

            /**
             * Which cutoff the monthly contributions come off.
             *
             * A policy rather than a rate, which is why it is settable at all:
             * the SSS percentage is the government's and lives in
             * configuration, but whether a firm loads a month of contributions
             * onto the first payslip or the second is the firm's own decision.
             * See `DeductionSchedule`.
             */
            'payroll_deduct_on' => ['sometimes', Rule::enum(DeductionSchedule::class)],

            /**
             * The days this firm's pay periods close on.
             *
             * The other payroll policy, and the one that used to be an
             * environment variable — which meant one cutoff for every haulier
             * on the install. `PayrollCutoffDays` carries the rules and the
             * reasons for each of them, including why an open draft run blocks
             * the change.
             *
             * Nullable, and that is how a firm goes back to the install
             * default rather than a state it has to guess its way out of.
             */
            'payroll_cutoff_days' => ['sometimes', 'nullable', 'array', new PayrollCutoffDays],
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.required_with' => 'A longitude needs its latitude.',
            'longitude.required_with' => 'A latitude needs its longitude.',
            'payroll_deduct_on' => 'Contributions come off both cutoffs, the first, or the second.',
        ];
    }

    /**
     * The fields to write, coordinates as numbers.
     *
     * Named `toAttributes` and not `attributes`: the latter is Laravel's own
     * hook for the display names in a validation message, and overriding it
     * with a payload would rename every field in every error this form raises.
     *
     * Cast here rather than left as the strings a query string or a JSON
     * document can carry, so what reaches the column is a number and what comes
     * back out of the cast is the same number.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $attributes = $this->safe()->only([
            'contact_name', 'contact_email', 'contact_phone', 'address', 'latitude', 'longitude',
            'payroll_deduct_on', 'payroll_cutoff_days',
        ]);

        foreach (['latitude', 'longitude'] as $key) {
            if (array_key_exists($key, $attributes) && $attributes[$key] !== null) {
                $attributes[$key] = (float) $attributes[$key];
            }
        }

        /**
         * Stored as ints, ascending.
         *
         * Sorted here rather than on the way out, so the column and the
         * calendar built from it are the same list in the same order. A client
         * that sent `[31, 15]` meant the same thing as one that sent `[15, 31]`
         * and should not leave the row looking different.
         *
         * An empty array is stored as null — "back to the install default" —
         * which is the same state the column starts in.
         */
        if (array_key_exists('payroll_cutoff_days', $attributes)) {
            $days = array_values(array_unique(array_map('intval', (array) $attributes['payroll_cutoff_days'])));
            sort($days);

            $attributes['payroll_cutoff_days'] = $days === [] ? null : $days;
        }

        return $attributes;
    }
}
