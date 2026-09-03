<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * The shared status vocabulary — DESIGN.md section 1.
 *
 * The API returns the *string*; the clients map it to a colour. A hex value
 * must never cross the wire.
 */
enum StatusValue: string
{
    case Active = 'active';
    case Delivered = 'delivered';
    case Available = 'available';
    case InTransit = 'in_transit';
    case Assigned = 'assigned';
    case Scheduled = 'scheduled';
    case Pending = 'pending';
    case Maintenance = 'maintenance';
    case Cancelled = 'cancelled';
    case Overdue = 'overdue';
    case Inactive = 'inactive';
    /**
     * Money that has actually arrived.
     *
     * Settling an invoice used to write `delivered`, which is the word for a
     * closed-out haul and reads as nonsense on a receivable. Worse, it made
     * "paid" and "delivered" the same value, so no page could count collected
     * money without also counting every delivered trip's document. This is
     * that distinction, and it is why the Dashboard can now separate money
     * owed from money in.
     */
    case Paid = 'paid';

    /** The four tones the clients render pills in. */
    public function tone(): Tone
    {
        return match ($this) {
            self::Active, self::Delivered, self::Paid => Tone::Success,
            self::Available, self::InTransit, self::Assigned, self::Scheduled => Tone::Info,
            self::Pending, self::Maintenance => Tone::Warning,
            self::Cancelled, self::Overdue, self::Inactive => Tone::Danger,
        };
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
