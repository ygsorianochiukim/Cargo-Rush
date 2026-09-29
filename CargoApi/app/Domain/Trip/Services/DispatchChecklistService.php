<?php

declare(strict_types=1);

namespace App\Domain\Trip\Services;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use Illuminate\Validation\ValidationException;

/**
 * The Safety, LTO and Warehouse Compliance Checklist, answered on the phone.
 *
 * The driver on the run ticks YES, NO or N/A on every line before the trip,
 * and the dispatch sheet prints with those boxes ticked. Answering again
 * replaces the answers — the sheet shows the latest, not a history.
 *
 * Not the pre-trip inspection. That is the pass/fail gate on Start and it
 * stays as it is; this is the firm's paper form, and a NO on it is for the
 * office to read, not a lock on the truck.
 */
class DispatchChecklistService
{
    public const ANSWERS = ['yes', 'no', 'na'];

    /**
     * @param  array<string, string>  $answers  line key => yes|no|na
     */
    public function record(Trip $trip, array $answers, ?string $remarks, string $checkedBy): Trip
    {
        abort_if(
            in_array($trip->status, [StatusValue::Delivered, StatusValue::Cancelled], true),
            422,
            'This run is finished — the dispatch checklist is answered before it leaves.',
        );

        $keys = TripTicketService::keys();
        $missing = array_values(array_diff($keys, array_keys($answers)));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'answers' => sprintf('Answer every line — %d still to tick.', count($missing)),
            ]);
        }

        $trip->forceFill([
            // Only the lines the form has, in its order.
            'dispatch_checklist' => array_combine($keys, array_map(fn (string $key): string => $answers[$key], $keys)),
            'dispatch_remarks' => $remarks !== null && trim($remarks) !== '' ? trim($remarks) : null,
            'dispatch_checked_at' => now(),
            'dispatch_checked_by' => $checkedBy,
        ])->save();

        return $trip->refresh();
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'answers' => ['required', 'array'],
            'answers.*' => ['required', 'string', 'in:'.implode(',', self::ANSWERS)],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
