<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Rules;

use App\Domain\Accounting\Posting\PostingRule;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Vehicle\Models\MaintenanceJob;
use Illuminate\Database\Eloquent\Model;

/**
 * A day on a truck's sheet: its takings and its six cost columns.
 *
 * The sheet is where Finance reads a company truck's income and running costs
 * (`FinanceService::pnlByTruck()`), so it is where the books read them too —
 * one entry per row, on the row's date.
 *
 * **Income against unbilled, not receivables.** The run is income the day it
 * is delivered, which is when the sheet is credited; its invoice may be raised
 * later, and is what makes the customer owe it. So the takings go Dr 1140
 * Unbilled trip income / Cr 4010, and the invoice clears 1140 into 1100 (see
 * `InvoiceRule`). Revenue is posted once, by the run, and receivables are
 * exactly what Billing says is owed.
 *
 * The costs are Dr their workbook account and Cr what paid them:
 *
 *   fuel, allowance, maintenance — cash, spent on the day;
 *   driver and helper pay — 2110, accrued until a pay run pays it (see
 *     `PayRunRule`, which takes the same pesos back out of payroll's gross);
 *   the owner's share on a revenue-share truck — 2020, owed to the owner
 *     through their wallet (the wallet row itself posts nothing; this does);
 *   maintenance a garage has billed us for — 1270, against the bill, rather
 *     than cash. Finance counts that job's cost here and leaves the bill's
 *     payments out, and so does this.
 *
 * Fuel is the whole Fuel column, fills that `/fuel` posted into it included.
 * Those fills post nothing of their own (`FuelFillRule`), which is how each
 * receipt is in the books once.
 */
class SheetDayRule extends PostingRule
{
    public function postings(Model $source): array
    {
        /** @var LedgerEntry $row */
        $row = $source;

        // Finance builds the roll-up truck by truck; a day on no listed truck
        // is in none of its figures, so it is in none of these either.
        $truck = Truck::query()->find($row->truck_id);

        if ($truck === null || $row->date === null) {
            return [];
        }

        $posting = $this->posting(
            'sheet',
            $row->date,
            JournalCategory::Operations,
            trim(sprintf('Daily sheet · %s · %s', $truck->plate ?? $truck->label, $row->route ?? $row->remarks ?? ''), ' ·'),
        )->about([
            'truck_id' => $truck->getKey(),
            'trip_id' => $row->trip_id,
            'customer_id' => $row->customer_id,
        ]);

        $cash = $this->code('cash');
        $crewPay = $this->code('crew_pay_accrued');

        $income = (int) $row->trip_income_cents;
        $posting->debit($this->code('unbilled_income'), $income, 'Trip income, to invoice')
            ->credit($this->code('freight_revenue'), $income, 'Trip income');

        $posting->debit($this->code('fuel'), (int) $row->fuel_cents, 'Fuel')
            ->credit($cash, (int) $row->fuel_cents, 'Fuel');

        $posting->debit($this->code('driver_salary'), (int) $row->driver_salary_cents, 'Driver salary')
            ->credit($crewPay, (int) $row->driver_salary_cents, 'Driver salary');

        $posting->debit($this->code('helper_salary'), (int) $row->helper_salary_cents, 'Helper salary')
            ->credit($crewPay, (int) $row->helper_salary_cents, 'Helper salary');

        $maintenance = (int) $row->maintenance_cents;
        $billed = max(0, min($maintenance, $this->billedMaintenance($row, $truck)));

        $posting->debit($this->code('maintenance'), $maintenance, 'Maintenance')
            ->credit($cash, $maintenance - $billed, 'Maintenance')
            ->credit($this->code('bills_not_expensed'), $billed, 'Maintenance, on the garage’s bill');

        $posting->debit($this->code('allowance'), (int) $row->allowance_cents, 'Allowance')
            ->credit($cash, (int) $row->allowance_cents, 'Allowance');

        $posting->debit($this->code('owner_share'), (int) $row->owner_share_cents, 'Owner’s share')
            ->credit($this->code('due_to_truckers'), (int) $row->owner_share_cents, 'Owner’s share');

        return [$posting];
    }

    public function label(Model $source): string
    {
        /** @var LedgerEntry $source */
        return sprintf('the daily sheet for %s', $source->date?->toDateString() ?? 'an undated day');
    }

    /**
     * How much of the day's Maintenance column a supplier's bill covers.
     *
     * The jobs that posted onto this truck's day and name a live bill. A job
     * posts into the day's first row (`FinanceService::openDailyRowForTruck`),
     * so a second row on the same day carries none of it.
     */
    private function billedMaintenance(LedgerEntry $row, Truck $truck): int
    {
        if ($truck->vehicle_id === null) {
            return 0;
        }

        $first = LedgerEntry::query()
            ->where('truck_id', $truck->getKey())
            ->whereDate('date', $row->date->toDateString())
            ->value('id');

        if ($first !== $row->getKey()) {
            return 0;
        }

        return (int) MaintenanceJob::query()
            ->where('vehicle_id', $truck->vehicle_id)
            ->where('posted_cents', '>', 0)
            ->whereNotNull('invoice_id')
            ->whereDate('completed_on', $row->date->toDateString())
            ->whereHas('invoice', static fn ($invoice) => $invoice->where('status', '!=', StatusValue::Cancelled->value))
            ->sum('posted_cents');
    }
}
