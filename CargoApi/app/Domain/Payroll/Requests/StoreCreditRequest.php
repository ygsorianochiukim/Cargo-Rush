<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Requests;

use App\Domain\Shared\Enums\StoreCreditKind;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * A line on somebody's store tab, as the counter enters it.
 *
 * Deliberately small. A *pautang* is written in a notebook at the till — a
 * date, what was taken, how much — and a form that asked for more than that is
 * a form nobody fills in, which puts the tab back in the notebook.
 */
class StoreCreditRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            /**
             * `charge` or `payment`. Goods taken, or money back.
             *
             * Defaults to a charge, which is what almost every row is — the
             * repayments are normally payroll's, written on approve.
             */
            'kind' => ['sometimes', Rule::enum(StoreCreditKind::class)],

            /**
             * Centavos, and always positive whichever kind it is.
             *
             * `min:1` because a zero row moves no balance and explains nothing;
             * it is somebody who meant to type a figure and did not.
             */
            'amount_cents' => ['required', 'integer', 'min:1'],

            /** "3 kg rice, 2 tins" — what was taken, in the storekeeper's words. */
            'description' => ['nullable', 'string', 'max:160'],

            /** Which outlet. A yard may run a canteen as well as a mini-mart. */
            'outlet' => ['nullable', 'string', 'max:60'],

            /**
             * The date it happened, not the date it was keyed.
             *
             * A tab is written up at the till and entered later, and a cutoff
             * is a pair of dates: a charge typed on the 16th for something
             * taken on the 14th belongs on the first payslip, and only this
             * field can say so.
             *
             * Not `before_or_equal:today` — an office keying yesterday's book
             * is the normal case and a clock an hour out should not refuse it.
             */
            'charged_on' => ['sometimes', 'date'],

            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount_cents.integer' => 'Send the amount in centavos as a whole number, not pesos.',
            'amount_cents.min' => 'A row has to move the balance by something.',
        ];
    }
}
