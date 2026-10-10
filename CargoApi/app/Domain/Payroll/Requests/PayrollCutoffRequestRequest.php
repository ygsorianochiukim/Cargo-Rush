<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Requests;

use App\Domain\Payroll\Rules\PayrollCutoffDays;
use App\Domain\Shared\Enums\DeductionSchedule;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class PayrollCutoffRequestRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'cutoff_days' => ['required', 'array', new PayrollCutoffDays(checkOpenDraft: false)],

            'payroll_deduct_on' => ['sometimes', 'nullable', Rule::enum(DeductionSchedule::class)],

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

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        $attributes = $this->safe()->only(['cutoff_days', 'payroll_deduct_on', 'reason']);

        $days = array_values(array_unique(array_map('intval', (array) $attributes['cutoff_days'])));
        sort($days);

        $attributes['cutoff_days'] = $days;

        return $attributes;
    }
}
