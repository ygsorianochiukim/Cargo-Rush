<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * How somebody is engaged.
 *
 * A short closed list, unlike `position`, which is deliberately free text: the
 * job titles a haulier invents are endless, but the ways it can employ someone
 * are set by labour law rather than by the office.
 *
 * ## Three of these are also pay tiers
 *
 * A position's rate card is written as three figures — trainee, probationary,
 * regular — because that is how a fleet actually prices a job: the same driver
 * seat is worth less in the first month than it is after regularisation, and an
 * office should be able to write both down once rather than remember the
 * difference on every hire.
 *
 * Contractual and part-time are engagements, not stages, and a firm running
 * them does not price them a fourth and fifth way. They read the regular
 * figure — see `tier()` — which keeps the rate card three columns wide instead
 * of five, and keeps both of these as valid employment types for the people
 * already on them.
 */
enum EmploymentType: string
{
    /** The first stage, and the cheapest rung of the rate card. */
    case Trainee = 'trainee';

    case Probationary = 'probationary';
    case Regular = 'regular';
    case Contractual = 'contractual';
    case PartTime = 'part_time';

    public function label(): string
    {
        return match ($this) {
            self::Trainee => 'Trainee',
            self::Probationary => 'Probationary',
            self::Regular => 'Regular',
            self::Contractual => 'Contractual',
            self::PartTime => 'Part-time',
        };
    }

    /**
     * Which of the rate card's three columns this engagement is paid from.
     *
     * The indirection is the whole point of having it as a method: an office
     * adding a sixth employment type later answers this question once, here,
     * rather than in every place that reaches for a figure. Contractual and
     * part-time answer `Regular` because a firm that takes somebody on for a
     * season is not paying them a trainee's rate — they are a full hand on a
     * different contract.
     */
    public function tier(): self
    {
        return match ($this) {
            self::Trainee => self::Trainee,
            self::Probationary => self::Probationary,
            self::Regular, self::Contractual, self::PartTime => self::Regular,
        };
    }

    /**
     * The three the rate card has a column for, in the order somebody moves
     * through them.
     *
     * @return self[]
     */
    public static function tiers(): array
    {
        return [self::Trainee, self::Probationary, self::Regular];
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
