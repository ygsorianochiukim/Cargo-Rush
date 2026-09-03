<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Where an application has got to.
 *
 * Its own vocabulary rather than `StatusValue`, because a hiring pipeline is a
 * sequence and the shared list is a set of states. "Screening" and "interview"
 * are not statuses any other module has, and folding both into `pending` —
 * which is what reusing the shared enum would mean — loses the only thing the
 * office wants from this screen: how far along each person is.
 *
 * The clients still render it as a pill, so it carries a tone like everything
 * else. The API returns the string; a hex value never crosses the wire
 * (DESIGN.md section 7.1).
 */
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

    /**
     * Is this stage still live?
     *
     * The applicants list leads with the open ones, and the badge on the nav
     * row counts them — a CV sitting unread is the failure the module exists to
     * prevent, and a hired or rejected application is not that.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Hired, self::Rejected], true);
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
