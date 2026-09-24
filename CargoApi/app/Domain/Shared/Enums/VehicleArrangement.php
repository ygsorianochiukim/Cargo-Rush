<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * On what terms a truck runs for the fleet.
 *
 * Every unit here dispatches identically — the board, the driver, the proof of
 * delivery and the customer's invoice are the same whoever owns the wheels.
 * What differs is only **where the money goes when the run closes**, and that
 * is the whole of this enum.
 *
 * Deliberately not the same question as `truckers`. A partner trucker is a
 * business the fleet brokers work to, with their own customers and their own
 * board; these are trucks running *as* the fleet, under its name, on the
 * fleet's own dispatch. A sub-contracted unit blurs that line and is the one
 * case that touches both — see `SubContracted`.
 */
enum VehicleArrangement: string
{
    /**
     * The fleet's own truck.
     *
     * It bought it, it maintains it, it keeps every peso the truck earns. The
     * default, and what every unit on the books was before this column existed
     * — which is why the migration backfills it and nothing about an existing
     * install changes.
     */
    case Owned = 'owned';

    /**
     * Hired in for a monthly fee.
     *
     * The fleet pays the owner a fixed rent — ₱50,000 a month is the going
     * rate — and in exchange keeps everything the truck earns. The rent is a
     * cost of the month rather than of any one run, so it is billed monthly
     * and lands as an expense against that unit; a truck that sat idle still
     * costs its rent, which is the risk the fleet took when it hired one.
     */
    case Rented = 'rented';

    /**
     * Hired in on a share of what it earns.
     *
     * No monthly rent at all. The fleet keeps its percentage of each run and
     * the owner takes the rest — the arrangement a ten-wheeler owner asks for,
     * because a truck that works hard should earn them more than a flat fee.
     * The mirror image of `Rented`: there the fleet carries the risk of an idle
     * truck, here the owner does.
     */
    case RentedShare = 'rented_share';

    /**
     * Somebody else's truck and somebody else's operator, running as the fleet.
     *
     * Money-wise identical to `RentedShare` — the fleet keeps its cut and the
     * rest is owed onwards — and different in one respect that matters: there
     * is a person behind it with the handset. They see the runs, watch their
     * own wallet and get paid run by run, exactly as a partner trucker does,
     * because they *are* one; the truck simply sits on the fleet's own roster
     * rather than on theirs.
     */
    case SubContracted = 'subcontracted';

    public function label(): string
    {
        return match ($this) {
            self::Owned => 'Owned',
            self::Rented => 'Rented',
            self::RentedShare => 'Rented — revenue share',
            self::SubContracted => 'Sub-contracted',
        };
    }

    /**
     * Does a delivery on this truck owe somebody outside the fleet a share?
     *
     * The one question `putOnTheBooks` asks. True for the two share
     * arrangements and false for the other two — an owned truck owes nobody,
     * and a rented one was already paid for by the month.
     */
    public function sharesRevenue(): bool
    {
        return $this === self::RentedShare || $this === self::SubContracted;
    }

    /** Is a monthly rent due on this truck whether it works or not? */
    public function chargesRent(): bool
    {
        return $this === self::Rented;
    }

    /**
     * The fleet's usual cut, in basis points, for a share arrangement.
     *
     * A starting point rather than a rule: the rate is per truck, because it
     * is a negotiation with whoever owns that one. These are the two the
     * business works to — 15% on a rented ten-wheeler, 12% on a
     * sub-contractor, matching what a partner trucker is on.
     */
    public function defaultShareBp(): ?int
    {
        return match ($this) {
            self::RentedShare => 1500,
            self::SubContracted => 1200,
            default => null,
        };
    }

    /** Is this an outside truck at all, however it is paid for? */
    public function isHired(): bool
    {
        return $this !== self::Owned;
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
