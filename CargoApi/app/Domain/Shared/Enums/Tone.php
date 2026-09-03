<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/** Semantic tone shared by status pills, toasts and activity rows. */
enum Tone: string
{
    case Success = 'success';
    case Info = 'info';
    case Warning = 'warning';
    case Danger = 'danger';
}
