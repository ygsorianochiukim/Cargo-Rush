<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Booking a service, and recording what it came to.
 *
 * One form for both, because they are one record seen at two moments: a job is
 * booked with a date and a kind, and costed later when the invoice comes back
 * from the garage. Splitting them into "schedule" and "close out" would make
 * the ordinary correction — a figure mistyped — into a decision about which
 * form to open.
 */
class MaintenanceJobRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        /**
         * Which truck — asked for only when the route did not already say.
         *
         * The nested route carries the unit in its path (`vehicles/{vehicle}/
         * maintenance`), so there it is neither needed nor honoured. Truck
         * Maintenance posts to a flat route from a screen that lists the whole
         * fleet, and there the unit is the first thing the form asks: a service
         * with no truck on it is a cost with nowhere to land.
         */
        $onAUnit = $this->route('vehicle') !== null;

        return [
            'vehicle_id' => [$onAUnit ? 'sometimes' : $required, 'string', 'exists:vehicles,id'],
            'kind' => [$required, 'string', 'max:80'],
            'due_at' => [$required, 'date'],
            'next_service_km' => ['sometimes', 'integer', 'min:0'],
            'status' => [
                'sometimes',
                Rule::in([
                    StatusValue::Scheduled->value,
                    StatusValue::Active->value,
                    StatusValue::Delivered->value,
                    StatusValue::Cancelled->value,
                ]),
            ],

            /**
             * What it cost, in centavos.
             *
             * Nullable and it matters: null is "nobody has told us yet" and
             * zero is a warranty job nobody was charged for. Clearing a figure
             * back to null takes it off the unit's sheet again, which is what
             * somebody correcting a mistake means by clearing it.
             *
             * Ten million is ₱100,000 — an engine rebuild, and past what any
             * single line should be without somebody looking at it twice.
             */
            'cost_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],

            /**
             * The day the work was done, which is the day it is charged to.
             *
             * Not `due_at`: a service booked for the 12th and done on the 19th
             * is the 19th's money. A cost with no completed date is charged to
             * nothing, because there is no day to charge it to.
             */
            'completed_on' => ['sometimes', 'nullable', 'date'],

            'supplier_id' => ['sometimes', 'nullable', 'string', 'exists:suppliers,id'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'Say which truck this was done on.',
            'cost_cents.integer' => 'Send the cost in centavos as a whole number, not pesos.',
            'cost_cents.max' => 'That is over ₱100,000 for one job — check the figure.',
        ];
    }

    /**
     * The fields to write.
     *
     * `only` on the *validated* payload rather than the raw input, so a client
     * that sent `posted_cents` cannot reach the column that keeps the job and
     * the daily sheet in agreement. See `MaintenanceService`.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return $this->safe()->only([
            'kind', 'due_at', 'next_service_km', 'status',
            'cost_cents', 'completed_on', 'supplier_id', 'reference', 'note',
        ]);
    }
}
