<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Support;

final class MonthlyShare
{
    public static function forRun(int $monthlyCents, int $index, int $count): int
    {
        if ($count <= 1) {
            return $monthlyCents;
        }

        $base = intdiv($monthlyCents, $count);

        return $index >= $count - 1
            ? $monthlyCents - $base * ($count - 1)
            : $base;
    }
}
