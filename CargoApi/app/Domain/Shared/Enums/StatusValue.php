<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

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
    case Partial = 'partial';
    case Paid = 'paid';

    public function tone(): Tone
    {
        return match ($this) {
            self::Active, self::Delivered, self::Paid => Tone::Success,
            self::Available, self::InTransit, self::Assigned, self::Scheduled => Tone::Info,
            self::Pending, self::Maintenance, self::Partial => Tone::Warning,
            self::Cancelled, self::Overdue, self::Inactive => Tone::Danger,
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
