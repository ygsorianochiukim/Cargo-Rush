<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Rules;

use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Support\PayrollCalendar;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * What a firm may set its pay cutoffs to.
 *
 * `PayrollCalendar::of()` is forgiving because it reads a column back and a
 * payslip is the wrong place to discover a bad row. This is the other half, and
 * it refuses rather than repairs: it sits on a form, and a form that silently
 * turns "the 5th, the 20th and the 31st" into two of those three has told
 * somebody their payroll is set up a way it is not.
 *
 * ## The rules, and what each one is stopping
 *
 *   **One or two days.** The statutory arithmetic downstream is semi-monthly —
 *   `DeductionSchedule` splits a monthly contribution across at most two
 *   payslips, and the withholding table is the BIR's semi-monthly one. A weekly
 *   payroll is a different tax table, not a longer list here.
 *
 *   **Distinct, in range.** Two cutoffs on the same day is one cutoff and an
 *   empty period beside it.
 *
 *   **The earlier one on the 27th or before.** February. A cutoff clamps to the
 *   last day of a short month, so an earlier cutoff on the 28th collides with a
 *   later one in a 28-day February and leaves the second period running from
 *   the 1st of March back to the 28th of February. At the 27th there is always
 *   a day left for the second period, in every month there is.
 *
 *   **No draft run open.** The one rule that is about *timing* rather than
 *   about the numbers, which is why it can be switched off — see below.
 *
 * ## Why the draft check is optional
 *
 * The four shape rules are facts about a pair of numbers: true today, true next
 * month, true whoever is asking. The draft rule is not. It is a fact about
 * right now, and it is the difference between the two ways a cutoff gets
 * changed here.
 *
 * Applying a change — `PATCH /company`, or approving a request — must check it,
 * because a draft run stores the dates it was opened on and moving the cutoffs
 * underneath it would have it paid against a period that no longer exists.
 *
 * **Filing a request must not.** An office noticing mid-fortnight that the
 * cutoff is wrong should be able to say so while this period's draft is still
 * open; that is exactly when somebody notices. Refusing the request would mean
 * the only moment the problem is visible is the one moment it cannot be
 * reported. The check runs again when an administrator approves it, which is
 * when it actually matters.
 *
 * ## One implementation, two callers
 *
 * `problemWith()` is the whole rule as a function, so the approval path in
 * `PayrollCutoffRequestService` asks the same question this form does rather
 * than keeping a second copy that can drift from it.
 */
class PayrollCutoffDays implements ValidationRule
{
    /**
     * @param  bool  $checkOpenDraft  False when this is a *request* to change
     *                                the cutoff rather than the change itself.
     *                                See the class note.
     */
    public function __construct(private readonly bool $checkOpenDraft = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = self::problemWith($value, $this->checkOpenDraft);

        if ($problem !== null) {
            $fail($problem);
        }
    }

    /**
     * The first thing wrong with these cutoff days, or null if nothing is.
     *
     * Returns one problem rather than all of them, and in the order somebody is
     * most likely to trip them: a person who sent three days does not also need
     * to be told the third is not a day of the month.
     *
     * @param  mixed  $value  Whatever arrived — this is validation, so it is not
     *                        assumed to be an array of anything.
     */
    public static function problemWith(mixed $value, bool $checkOpenDraft = true): ?string
    {
        if (! is_array($value)) {
            return 'Cutoff days are a list of one or two day numbers.';
        }

        $days = array_values($value);

        if ($days === [] || count($days) > PayrollCalendar::MAX_CUTOFFS) {
            return sprintf(
                'Payroll is cut off once or twice a month, so give one or two days — not %d. '
                .'A weekly payroll needs a different tax table and is not set up here.',
                count($days),
            );
        }

        foreach ($days as $day) {
            if (! is_numeric($day) || (int) $day != $day || (int) $day < 1 || (int) $day > PayrollCalendar::LAST_DAY) {
                return 'A cutoff day is a day of the month, from 1 to 31. Use 31 for the end of the month.';
            }
        }

        $days = array_map('intval', $days);

        if (count(array_unique($days)) !== count($days)) {
            return 'The two cutoff days have to be different days.';
        }

        sort($days);

        if (count($days) === 2 && $days[0] > PayrollCalendar::LATEST_FIRST_CUTOFF) {
            return sprintf(
                'The earlier cutoff has to be %s or before. Past that it collides with the second one in February, '
                .'which would leave the month with only one pay period.',
                PayrollCalendar::dayLabel(PayrollCalendar::LATEST_FIRST_CUTOFF),
            );
        }

        // Last, because it is the only check that touches the database and the
        // four above are the ones somebody is more likely to trip.
        if ($checkOpenDraft) {
            $draft = self::openDraft();

            if ($draft !== null) {
                return sprintf(
                    '%s is still a draft for %s. Approve it or delete it before moving the cutoff — '
                    .'otherwise it would be paid on a period that no longer exists.',
                    $draft->reference,
                    $draft->periodLabel(),
                );
            }
        }

        return null;
    }

    /**
     * The draft run standing in the way, if there is one.
     *
     * Public because approving a request has to ask the same question, and
     * because a screen offering the approval is better for being able to say
     * *which* run is blocking it before somebody clicks.
     */
    public static function openDraft(): ?PayRun
    {
        return PayRun::query()->where('status', PayRun::DRAFT)->first();
    }
}
