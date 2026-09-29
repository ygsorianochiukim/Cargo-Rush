<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Services;

use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\Truck;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The monthly bill for a truck the fleet hires at a flat fee.
 *
 * ## Why this is a monthly job and not part of a delivery
 *
 * A rented truck costs its rent whether it turned a wheel or not. That is the
 * whole difference between it and a revenue-share unit: there the owner carries
 * the risk of a quiet month, here the fleet does, and a cost that only appeared
 * when work happened would quietly hide exactly the month the office most needs
 * to see it in.
 *
 * So it is charged per truck per month, for the whole month, from the month's
 * first day — due on its last. It sits in Payables all month, and one still
 * unpaid after the month ends is overdue. (It used to be charged on the 1st
 * for the month just ended, which left the month being worked with no rent
 * showing anywhere.)
 *
 * ## Why an expense rather than a table of its own
 *
 * Because that is what it is, and the Other Expenses module already does all
 * of it: a category, a payee, a date, an amount, an optional truck to attribute
 * it to, and a place in the period totals and the profitability page. A
 * `truck_rents` table would have been a second, thinner copy of that with its
 * own screen to build and its own figures to reconcile.
 *
 * Attributed to the unit's ledger truck where there is one, so the rent lands
 * on that truck's profitability rather than in fleet overhead. A rented truck
 * that earns ₱180,000 and costs ₱50,000 should read as a truck that made
 * ₱130,000, and it only does if the rent is against it.
 *
 * ## Running it twice
 *
 * Does nothing the second time. The reference is derived from the unit and the
 * month, so a re-run finds the row it already wrote — which matters, because
 * the natural fix for a missed cron is to run it by hand and nobody should have
 * to check first whether that double-bills a month.
 */
class TruckRentService
{
    /** The category rent is filed under. Created on first use. */
    public const CATEGORY_KEY = 'truck-rental';

    /**
     * Bill every flat-rented truck for the month containing `$on`.
     *
     * @return int how many charges were raised — zero on a re-run.
     */
    public function chargeMonth(CarbonInterface $on): int
    {
        $month = $on->copy()->startOfMonth();
        $category = $this->category();
        $raised = 0;

        $hired = Vehicle::query()
            ->where('arrangement', VehicleArrangement::Rented->value)
            ->whereNotNull('rent_cents')
            ->where('rent_cents', '>', 0)
            ->get();

        foreach ($hired as $vehicle) {
            if ($this->charge($vehicle, $month, $category) !== null) {
                $raised++;
            }
        }

        return $raised;
    }

    /**
     * Put every flat-rented truck's rent on the books for the month `$today`
     * is in, and catch up the month before if it was missed.
     *
     * The whole month's rent is owed from its first day and due on its last,
     * so it shows in Payables for the full month — payable any time in it —
     * and a month still unpaid once it has ended reads as overdue. The catch-up
     * is what makes a missed run harmless: whichever day this next runs, last
     * month is there too. Never a month before the truck was on the books.
     *
     * Safe to run every day: a month already charged is skipped.
     *
     * @return int how many charges were raised
     */
    public function chargeDue(CarbonInterface $today): int
    {
        $category = $this->category();
        $raised = 0;

        $hired = Vehicle::query()
            ->where('arrangement', VehicleArrangement::Rented->value)
            ->whereNotNull('rent_cents')
            ->where('rent_cents', '>', 0)
            ->get();

        foreach ($hired as $vehicle) {
            $raised += $this->chargeDueFor($vehicle, $today, $category);
        }

        return $raised;
    }

    /**
     * One truck's rent, this month and a missed last month.
     *
     * Also called the moment a truck is put on the books as rented, so its
     * rent is in Payables from that day rather than from the next run.
     */
    public function chargeDueFor(Vehicle $vehicle, CarbonInterface $today, ?ExpenseCategory $category = null): int
    {
        if (! $vehicle->chargesRent()) {
            return 0;
        }

        $current = $today->copy()->startOfMonth();
        $since = ($vehicle->created_at ?? $today)->copy()->startOfMonth();
        $raised = 0;

        foreach ([$current->copy()->subMonth(), $current] as $month) {
            if ($month->lt($since)) {
                continue;
            }

            if ($this->charge($vehicle, $month, $category) !== null) {
                $raised++;
            }
        }

        return $raised;
    }

    /**
     * One truck, one month.
     *
     * Returns null when the month is already billed, which is what makes the
     * whole thing safe to re-run.
     */
    public function charge(Vehicle $vehicle, CarbonInterface $month, ?ExpenseCategory $category = null): ?Expense
    {
        if (! $vehicle->chargesRent()) {
            return null;
        }

        $period = $month->copy()->startOfMonth();

        /**
         * One charge per unit per month — keyed on the **vehicle**, not the
         * plate.
         *
         * The reference used to be the idempotency: "RENT <plate> <month>",
         * looked up by string. Two things broke it. A hired unit with no plate
         * yet made "RENT  2026-09", so the second plateless unit found the
         * first one's charge and was never billed; and correcting a plate
         * mid-month made a new string, so the same month was charged twice.
         * The unit's id changes for neither, and the charge is dated to the
         * month's last day, so that pair is the key.
         */
        $existing = Expense::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->whereHas('category', static fn ($query) => $query->where('key', self::CATEGORY_KEY))
            ->whereDate('date', $period->copy()->endOfMonth()->toDateString())
            ->exists();

        if ($existing) {
            return null;
        }

        // Still what somebody reads on the expense line — "RENT ABC-1234
        // 2026-09" says what it is without opening anything — but no longer
        // what finds it.
        $reference = sprintf('RENT %s %s', $vehicle->plate ?? 'UNPLATED', $period->format('Y-m'));

        return DB::transaction(fn (): Expense => Expense::create([
            'category_id' => ($category ?? $this->category())->getKey(),
            // Against the unit's own sheet where it has one, so the rent lands
            // on that truck's profitability rather than in fleet overhead.
            'truck_id' => $this->ledgerTruckFor($vehicle),
            'vehicle_id' => $vehicle->getKey(),
            // Dated to the end of the month it covers: the cost belongs to that
            // period, whichever morning the job happened to run.
            'date' => $period->copy()->endOfMonth()->toDateString(),
            'amount_cents' => (int) $vehicle->rent_cents,
            'currency' => 'PHP',
            'payee' => $vehicle->owner_name,
            'reference' => $reference,
            'note' => sprintf('Monthly rent for %s', $period->format('F Y')),
            // Raised, not paid. Whoever settles it says so, in the same place
            // every other bill is settled.
            'status' => StatusValue::Pending->value,
        ]));
    }

    /**
     * The workbook sheet this unit files against, if it has one.
     *
     * Null is an ordinary answer rather than a problem — a truck that has
     * never carried anything has no sheet yet, and the rent is then fleet
     * overhead until it does.
     */
    private function ledgerTruckFor(Vehicle $vehicle): ?string
    {
        return Truck::query()->where('vehicle_id', $vehicle->getKey())->value('id');
    }

    /**
     * The expense category rent is filed under, created if the office has not
     * got one.
     *
     * `firstOrCreate` and matched on the key, like `ExpenseCategorySeeder`:
     * these rows belong to the office once they exist, so a renamed or
     * reordered category is recognised rather than duplicated or reset.
     */
    private function category(): ExpenseCategory
    {
        return ExpenseCategory::firstOrCreate(['key' => self::CATEGORY_KEY], [
            'name' => 'Truck Rental',
            'description' => 'Monthly fee for a truck the fleet hires at a flat rate.',
            'icon' => 'fleet',
            'position' => 25,
        ]);
    }
}
