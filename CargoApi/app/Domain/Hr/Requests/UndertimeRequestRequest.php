<?php

declare(strict_types=1);

namespace App\Domain\Hr\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;

/**
 * `hours` is derived from the two times, for the same reason a leave's `days`
 * is derived from its dates.
 */
class UndertimeRequestRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'employee_id' => [$required, 'string', 'exists:employees,id'],
            'date' => [$required, 'date'],
            // `H:i` — a time of day, not a timestamp. Undertime is a slice out
            // of one shift, and a timestamp would invite one spanning midnight.
            'from_time' => [$required, 'date_format:H:i'],
            'to_time' => [$required, 'date_format:H:i', 'after:from_time'],
            'reason' => [$required, 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'from_time.date_format' => 'Use a 24-hour time, like 15:30.',
            'to_time.date_format' => 'Use a 24-hour time, like 15:30.',
            'to_time.after' => 'The end time has to be later than the start time.',
        ];
    }
}
