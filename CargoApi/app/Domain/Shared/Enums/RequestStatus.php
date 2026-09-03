<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Where a leave or undertime request has got to.
 *
 * Its own vocabulary rather than `StatusValue`, which has no word for
 * "approved" — the nearest it offers is `active`, and an approved leave that
 * has not started yet is not active in any sense the rest of the app means.
 * Overloading it would also make "rejected" indistinguishable from
 * "cancelled", and those are opposite events: one is the office declining, the
 * other is the employee withdrawing.
 */
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

    /**
     * Does this request take somebody off the road?
     *
     * Only an approved one. Counting a pending request against attendance
     * would penalise an employee for asking, and counting a rejected one would
     * be plainly wrong.
     */
    public function counts(): bool
    {
        return $this === self::Approved;
    }

    /** Still on somebody's desk. What the nav badge counts. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
