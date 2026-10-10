<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum PayComponentSchedule: string
{
    case EachRun = 'each_run';

    case MonthlySplit = 'monthly_split';

    case FirstCutoff = 'first_cutoff';

    case SecondCutoff = 'second_cutoff';

    public function label(): string
    {
        return match ($this) {
            self::EachRun => 'On every payslip',
            self::MonthlySplit => 'Monthly, split across both cutoffs',
            self::FirstCutoff => 'Monthly, all on the first cutoff',
            self::SecondCutoff => 'Monthly, all on the second cutoff',
        };
    }

    public function detail(): string
    {
        return match ($this) {
            self::EachRun => 'The full amount appears on every payslip, so a semi-monthly payroll pays it twice a month.',
            self::MonthlySplit => 'Half the monthly amount comes off each payslip.',
            self::FirstCutoff => 'The whole monthly amount is on the first payslip. The second carries none.',
            self::SecondCutoff => 'The whole monthly amount is on the second payslip. The first carries none.',
        };
    }

    public function isMonthly(): bool
    {
        return $this !== self::EachRun;
    }

    public function shareOf(int $monthlyCents, int $index, int $count): int
    {
        return match ($this) {
            self::EachRun => $monthlyCents,
            self::MonthlySplit => DeductionSchedule::Split->shareOf($monthlyCents, $index, $count),
            self::FirstCutoff => DeductionSchedule::FirstCutoff->shareOf($monthlyCents, $index, $count),
            self::SecondCutoff => DeductionSchedule::SecondCutoff->shareOf($monthlyCents, $index, $count),
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
