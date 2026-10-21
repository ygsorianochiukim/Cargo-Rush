<?php

declare(strict_types=1);

namespace App\Domain\Printing\Printables\Concerns;

use App\Domain\Printing\Support\Layout;

trait DrawsBoxes
{
    /** @return array<string, mixed> */
    protected static function box(string $id, string $label, string $zone, float $x, float $y, float $w, float $h, string $color = '#e6eaef', ?string $fill = null): array
    {
        return Layout::box($id, $label, $zone, $x, $y, $w, $h, $color, $fill);
    }
}
