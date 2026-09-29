<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Has Cargo Rush looked at a trucker's truck?
 *
 * Separate from the truck's `status`, and not interchangeable with it. `status`
 * is the trucker's own switch — on the road or in the shop — and this is the
 * office's decision, taken once from the photographs. A truck carries a load
 * only when both say yes, the same two-part rule the trucker themselves is
 * under (`Trucker::status` and `is_online`).
 */
enum TruckVerification: string
{
    /** Photographs sent, nobody at the office has checked them yet. */
    case Pending = 'pending';

    /** Checked against the photographs. May be put under a load. */
    case Verified = 'verified';

    /** Turned down, with a reason the trucker can read and fix. */
    case Rejected = 'rejected';
}
