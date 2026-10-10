<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum Tone: string
{
    case Success = 'success';
    case Info = 'info';
    case Warning = 'warning';
    case Danger = 'danger';
}
