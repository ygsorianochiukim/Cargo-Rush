<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Rules;

use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Support\PayrollCalendar;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PayrollCutoffDays implements ValidationRule
{
    public function __construct(private readonly bool $checkOpenDraft = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = self::problemWith($value, $this->checkOpenDraft);

        if ($problem !== null) {
            $fail($problem);
        }
    }

    public static function problemWith(mixed $value, bool $checkOpenDraft = true): ?string
    {
        if (! is_array($value)) {
            return 'Cutoff days are a list of day numbers.';
        }

        $days = array_values($value);

        if ($days === [] || count($days) > PayrollCalendar::MAX_CUTOFFS) {
            return sprintf(
                'Payroll is cut off up to three times a month, so give one, two or three days — not %d. '
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
            return 'The cutoff days have to be different days.';
        }

        sort($days);

        if (array_slice($days, 0, -1) !== []
            && max(array_slice($days, 0, -1)) > PayrollCalendar::LATEST_FIRST_CUTOFF) {
            return sprintf(
                'The earlier cutoff has to be %s or before. Past that it collides with the second one in February, '
                .'which would leave the month with only one pay period.',
                PayrollCalendar::dayLabel(PayrollCalendar::LATEST_FIRST_CUTOFF),
            );
        }

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

    public static function openDraft(): ?PayRun
    {
        return PayRun::query()->where('status', PayRun::DRAFT)->first();
    }
}
