<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum RequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting decision',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Withdrawn',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Approved => Tone::Success,
            self::Pending => Tone::Warning,
            self::Rejected => Tone::Danger,
            self::Cancelled => Tone::Info,
        };
    }

    public function counts(): bool
    {
        return $this === self::Approved;
    }

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
