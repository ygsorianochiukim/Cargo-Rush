<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * How the work reached the person who hauled it.
 *
 * Two values, and the difference between them is who holds the customer's
 * money — which is the only thing that decides which way a partner's wallet
 * moves when the run is delivered.
 *
 * See the migration that added `trips.booking_source` for the argument. The
 * short version: the percentage is the same either way, the direction is not.
 */
enum BookingSource: string
{
    /**
     * The haulier brokered it.
     *
     * The desk quoted the customer, confirmed the run and handed it to
     * whoever is hauling it — its own crew, or a partner it assigned. The
     * invoice is the haulier's and so is the collection, so a partner is paid
     * out of money the haulier already has.
     *
     * The default, and true of every run booked before partners existed.
     */
    case CargoRush = 'cargo_rush';

    /**
     * The customer picked the partner themselves.
     *
     * They chose that trucker off the hauler list rather than leaving it to the
     * fleet, and the trucker accepted. What follows is between the two of them
     * — the partner bills, the partner collects — and the haulier's interest is
     * the cut of a run whose money it never touches.
     */
    case Direct = 'direct';

    public function label(): string
    {
        return match ($this) {
            self::CargoRush => 'Cargo Rush',
            self::Direct => 'Direct booking',
        };
    }

    /**
     * Does the haulier collect the customer's money on this run?
     *
     * The one question the whole enum exists to answer, asked here rather than
     * matched on at each call site — there are four of them (the invoice, the
     * wallet entry, the statement and the screen), and four copies of the same
     * `match` is four chances for one of them to disagree with the others.
     *
     * True means an ordinary receivable is raised and the partner's share is a
     * credit. False means no receivable at all: billing a customer for a run
     * the partner already collected on would be charging them twice.
     */
    public function collectedByCarrier(): bool
    {
        return $this === self::CargoRush;
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
