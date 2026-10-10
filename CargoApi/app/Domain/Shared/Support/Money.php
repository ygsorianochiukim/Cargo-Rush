<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

final class Money
{
    public static function inWords(int $cents, string $currency = 'PHP'): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);

        $units = intdiv($cents, 100);
        $fraction = $cents % 100;

        $words = self::spell($units);
        $unitName = self::unitName($currency, $units);

        $amount = sprintf(
            '%s %s %s',
            ucfirst($words),
            $unitName,
            $fraction === 0 ? 'only' : sprintf('and %02d/100', $fraction),
        );

        return $negative ? 'Minus '.lcfirst($amount) : $amount;
    }

    private static function spell(int $units): string
    {
        if (! class_exists(\NumberFormatter::class)) {
            return number_format($units);
        }

        $formatter = new \NumberFormatter('en', \NumberFormatter::SPELLOUT);
        $words = $formatter->format($units);

        if ($words === false) {
            return number_format($units);
        }

        return trim(str_replace([' and ', '  '], [' ', ' '], $words));
    }

    private static function unitName(string $currency, int $units): string
    {
        return match (strtoupper($currency)) {
            'PHP' => $units === 1 ? 'peso' : 'pesos',
            default => strtoupper($currency),
        };
    }
}
