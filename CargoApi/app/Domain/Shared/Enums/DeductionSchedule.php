<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

use App\Domain\Payroll\Support\MonthlyShare;

enum DeductionSchedule: string
{
    case Split = 'split';

    case FirstCutoff = 'first';

    case SecondCutoff = 'second';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Split => 'Split across every run',
            self::FirstCutoff => 'All on the first run',
            self::SecondCutoff => 'All on the second run',
        };
    }

    public function detail(int $runs = 2): string
    {
        return match ($this) {
            self::Split => $runs <= 1
                ? 'The whole monthly contribution comes off the single payslip.'
                : sprintf(
                    'Each monthly contribution is split %s — %s of it comes off each payslip.',
                    $runs === 2 ? 'in half' : 'evenly across the '.$runs.' runs',
                    $runs === 2 ? 'half' : 'a third',
                ),
            self::FirstCutoff => 'The whole month of SSS, PhilHealth and Pag-IBIG comes off the first payslip. The rest carry none.',
            self::SecondCutoff => 'The whole month of SSS, PhilHealth and Pag-IBIG comes off the second payslip. The rest carry none.',
        };
    }

    public function shareOf(int $monthlyCents, int $index, int $count): int
    {
        if ($count <= 1) {
            return $monthlyCents;
        }

        return match ($this) {
            self::Split => MonthlyShare::forRun($monthlyCents, $index, $count),

            self::FirstCutoff => $index === 0 ? $monthlyCents : 0,

            self::SecondCutoff => $index === 1 ? $monthlyCents : 0,
        };
    }

    public function carriedOn(int $index, int $count): bool
    {
        return $this->shareOf(100, $index, $count) > 0;
    }
}
