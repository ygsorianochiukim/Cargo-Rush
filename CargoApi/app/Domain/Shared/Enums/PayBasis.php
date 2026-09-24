<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * How somebody's pay is arrived at.
 *
 * A fleet does not pay everybody the same way, and until this existed the
 * system could only express one of the three: a monthly salary. Anybody paid
 * per trip or per day had a salary of zero and was simply left off every pay
 * run — which was the right answer to the wrong question, because their money
 * was going out through the daily truck sheet and never reaching a payslip, a
 * contribution, or the books.
 *
 * ## The three
 *
 * **Monthly** is the office: a figure agreed once, split across the month's
 * cutoffs.
 *
 * **Daily** is the yard hand and the relief driver — a rate for a day worked,
 * multiplied by the days they actually worked in the period. The days come off
 * the truck sheet, because that is the record of who was out.
 *
 * **PerTrip** is the driver and the helper on a rate per haul, multiplied by
 * the hauls they delivered in the period.
 *
 * ## Every basis now carries a figure, and that is a change
 *
 * Per-trip pay used to hold no rate at all. The amount was whatever the office
 * had already written in the day's `driver_salary` column on the truck sheet,
 * and payroll summed that column. The argument was that a fleet's per-trip
 * arrangements are endless and a rate card here would be wrong within a month.
 *
 * What that cost was the thing the rate card is for: there was no answer to
 * "what does a driver get per trip", so every hire was a fresh negotiation
 * typed onto a sheet, and raising one driver meant finding every future row
 * somebody would write about them. A per-trip rate on the contract answers it
 * once and raises one person by appending one row.
 *
 * The sheet has not stopped mattering — it is still where the days come from,
 * and it is still where the money actually spent on a haul is recorded. What it
 * no longer decides is what the payslip says.
 *
 * ## The basis is the whole instruction
 *
 * Setting somebody to `per_trip` puts them on every pay run. There is no second
 * switch anywhere that can veto it — there used to be, and finding a driver on
 * no payslip because of a setting on another screen is the kind of surprise
 * that makes a payroll module feel untrustworthy.
 *
 * Which leaves one thing for an office to know: a firm that hands drivers their
 * trip money **in cash against that same sheet** should leave them on `monthly`
 * with no salary, which keeps them off a run. Paying it here as well would pay
 * it twice.
 */
enum PayBasis: string
{
    /** A monthly salary, split across the month's cutoffs. The office. */
    case Monthly = 'monthly';

    /** A rate for each day worked, counted off the truck sheet. */
    case Daily = 'daily';

    /** A rate for each haul delivered in the period. */
    case PerTrip = 'per_trip';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly salary',
            self::Daily => 'Daily rate',
            self::PerTrip => 'Per trip',
        };
    }

    /** What the figure beside it means, for somebody reading the rate card. */
    public function unit(): string
    {
        return match ($this) {
            self::Monthly => 'a month',
            self::Daily => 'a day',
            self::PerTrip => 'a trip',
        };
    }

    /** What it means on a payslip, for somebody reading the screen. */
    public function detail(): string
    {
        return match ($this) {
            self::Monthly => 'A fixed monthly salary, split across the month’s payslips.',
            self::Daily => 'The daily rate for each day they were out, counted from the truck sheet.',
            self::PerTrip => 'The trip rate for each haul they delivered in the period.',
        };
    }

    /**
     * Is the figure multiplied by work done in the period?
     *
     * The question that decides almost everything downstream: who is on a run,
     * whether the double-pay setting applies to them, and whether they need a
     * `drivers` record for their work to be findable at all.
     *
     * True for daily and per-trip, and the two count different things — days
     * off the truck sheet, hauls off the trip record. False for monthly, where
     * the figure is the period's pay on its own.
     *
     * Named for what it asks rather than where the answer is read from: this
     * used to be `readsTripSheet()`, which stopped being true of per-trip pay
     * the moment the rate moved onto the contract and the count moved onto the
     * trips.
     */
    public function countsWork(): bool
    {
        return $this !== self::Monthly;
    }

    /** @return string[] every value, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
