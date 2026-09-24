<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Requests;

use App\Domain\Payroll\Rules\PayrollCutoffDays;
use App\Domain\Shared\Enums\DeductionSchedule;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Asking for the pay cutoff to be moved.
 *
 * The same shape rules the settings form applies, with one switched off: the
 * **open draft run** check. That is not laxity, it is the point of a request.
 * An office notices the cutoff is wrong while running payroll on it — which is
 * precisely when a draft is open — and refusing the request then would mean the
 * only moment the problem is visible is the one moment it cannot be reported.
 * The check runs again at approval, which is when moving the setting would
 * actually break something. See `PayrollCutoffDays`.
 */
class PayrollCutoffRequestRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'cutoff_days' => ['required', 'array', new PayrollCutoffDays(checkOpenDraft: false)],

            /**
             * Optional, and null means "leave it alone".
             *
             * Most requests are about the cutoff only — the two settings share
             * a card because they are one conversation, but an office asking
             * to move its fortnight rarely has a view on which payslip the
             * contributions land on.
             */
            'payroll_deduct_on' => ['sometimes', 'nullable', Rule::enum(DeductionSchedule::class)],

            /**
             * Required, unlike almost every other free-text field here.
             *
             * An administrator deciding this is being asked to change the shape
             * of every future pay period on somebody else's judgement. "The
             * yard moved its week" is the difference between a decision and a
             * rubber stamp, and a blank box would make every request identical.
             */
            'reason' => ['required', 'string', 'min:4', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the cutoff should move — whoever decides this is not in the room.',
            'reason.min' => 'A few more words, so the reason still makes sense to somebody reading it next month.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $attributes = $this->safe()->only(['cutoff_days', 'payroll_deduct_on', 'reason']);

        // Ascending, as the column on `companies` holds them — so what is
        // approved is byte-for-byte what gets written, whichever order the
        // form collected the two days in.
        $days = array_values(array_unique(array_map('intval', (array) $attributes['cutoff_days'])));
        sort($days);

        $attributes['cutoff_days'] = $days;

        return $attributes;
    }
}
