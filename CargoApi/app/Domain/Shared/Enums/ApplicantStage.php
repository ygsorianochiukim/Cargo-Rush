<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum ApplicantStage: string
{
    case Applied = 'applied';
    case Screening = 'screening';
    case Interview = 'interview';
    case Offered = 'offered';
    case Hired = 'hired';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Applied => 'Applied',
            self::Screening => 'Screening',
            self::Interview => 'Interview',
            self::Offered => 'Offer out',
            self::Hired => 'Hired',
            self::Rejected => 'Not proceeding',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Hired => Tone::Success,
            self::Screening, self::Interview, self::Offered => Tone::Info,
            self::Applied => Tone::Warning,
            self::Rejected => Tone::Danger,
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Hired, self::Rejected], true);
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
