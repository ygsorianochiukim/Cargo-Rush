<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Billing\Repositories\InvoiceRepository;
use App\Domain\Finance\DTO\LedgerEntryData;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Finance\Repositories\ExpenseRepository;
use App\Domain\Finance\Repositories\LedgerRepository;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Fuel\Repositories\FuelRepository;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Services\WalletService;
use App\Domain\Vehicle\Models\MaintenanceJob;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The money side of the business — the server half of `core/finance.ts`.
 *
 * The two formulas from DESIGN.md section 5.1 live here and nowhere else:
 *
 *   total_income   = trip_income (company trucks, from the daily sheet)
 *                    + commission on partners' runs
 *                    + other income (receivable invoices raised by hand)
 *   total_expenses = fuel + driver_salary + helper_salary + maintenance
 *                    + allowance + owner_share + categorised expense lines
 *                    + fills logged at /fuel that no sheet row carries
 *                    + supplier bills actually paid
 *                    + payroll paid, beyond what the sheet's crew columns hold
 *   net_income     = total_income - total_expenses
 *
 * ## Income from partners: only what the fleet keeps
 *
 * On a ₱5,000 run a partner hauls, the fleet's income is its 12% — ₱600 — and
 * nothing else. The other ₱4,400 is the partner's: the fleet may collect it
 * from the customer and hand it on, but it never was the fleet's, so it is
 * neither income when it comes in nor an expense when it goes out. VAT on the
 * invoice (₱600 on top, for the customer to pay) is the government's, and is
 * shown beside the income as `vat_collected_cents` rather than inside it.
 *
 * This replaced counting partners' payouts as an expense against no income at
 * all, which made every partner run look like a ₱4,400 loss. The sections
 * below on payouts describe that earlier arrangement; the payout figure is
 * still reported, as `trucker_payouts_cents`, but no longer subtracted.
 *
 * The five columns are the transcribed workbook's; the lines are everything a
 * real day spends that those columns had no place for (`Expense`). They are
 * added, not reconciled. A legacy **Fuel category** expense line still counts
 * on its own — it is a separate receipt somebody filed before `/fuel` was the
 * place for fuel — so a fill-up filed both as a Fuel line and at `/fuel` is
 * still two figures, and the office should retire the line.
 *
 * ## Fuel: `/fuel` is the one source
 *
 * A fill used to be counted twice — once as the fill logged at `/fuel`, once
 * as whatever was typed into the sheet's `fuel_cents` for the same receipt.
 * Now an `active` fill **posts itself** into its truck's row for the day (see
 * `FuelPostingService`, the same drift-safe pattern as a maintenance job), the
 * sheet's `fuel_cents` cannot be typed by hand on a day fills have posted to,
 * and the roll-ups read the column rather than adding the fills on top. Each
 * receipt is counted exactly once, in the Fuel column of the truck it fed.
 *
 * What still gets added separately is only what no row carries: a fill for a
 * vehicle no truck points at, which is overhead — a real cost of the period
 * that no unit on the sheet is for — and a fill logged before posting existed
 * that `cargo:post-fuel-fills` has not yet put on its sheet. A pending fill is
 * a request nobody has approved and a cancelled one never happened; neither
 * posts nor counts.
 *
 * ## The supplier bills, and why they count on the day they were paid
 *
 * A bill raised against the fleet in Billing was in none of the above. It is
 * not a ledger column and it is not an `Expense` row — so a quarter's net
 * income was the takings less what the trucks cost, with the suppliers left out
 * of it altogether, and read higher than the business had actually made.
 *
 * What is counted is the money **allocated to payable invoices by payments
 * dated inside the window**. Three consequences, each a decision rather than an
 * accident:
 *
 *   A bill nobody has paid counts nothing. It is a commitment, and the figure
 *   these screens exist to give is what the period actually came to.
 *
 *   A bill half paid counts half. The allocations are the money; the face value
 *   of the document is only what was asked for.
 *
 *   A June bill settled in July is July's. The day it left the bank is the only
 *   date on which it is true.
 *
 * That makes this one figure a cash one among accruals, and it is the right
 * trade here: the alternative is a closed quarter that moves every time
 * somebody settles an old bill. The accrual view of the same money is the
 * income statement under Accounting — a different report for a different
 * reader.
 *
 * ## Paying a partner is money leaving, and used to be invisible
 *
 * The same hole as the supplier bills, in the place it was least visible.
 * Paying a partner trucker made the business look **better**: their wallet
 * balance fell, so `payables_cents` fell with it, so `actual_income_cents`
 * rose — and nothing anywhere recorded that the money had gone. An office that
 * settled with three partners on Friday read a healthier quarter on Monday.
 *
 * So a payout that has landed is a cost of the window it landed in, on the same
 * cash footing as a paid bill. `WalletService::paidOutBetween()` is the figure,
 * and it carries the one exclusion that keeps it honest: the owner of a truck
 * the fleet hired on a revenue share is left out, because that share is already
 * on the daily sheet as `owner_share_cents` the day the run was delivered.
 * Counting the payout too would charge the fleet twice for one haul.
 *
 * **What this deliberately does not do** is put the other side of a partner's
 * run into the income. A run a partner hauled files no sheet row (see
 * `TripService::putOnTheBooks`), so the customer's side of it is not in
 * `trip_income_cents` and this does not change that. The figure below is what
 * left the bank, which is the question the screens are being asked.
 *
 * ## And what is still owed, which is not an expense at all
 *
 * `payables_cents` is everything the fleet still owes as the period closed —
 * partner wallets, unsettled spend, unpaid supplier bills — and it is **not**
 * in `total_expenses_cents` and not in `net_income_cents`. It cannot be: an
 * unpaid bill has cost the period nothing, and adding it to the expenses would
 * charge the quarter for money that has not moved and then charge it again on
 * the day it does.
 *
 * It is carried beside them because the question an office actually asks is not
 * what the quarter earned but what is left of it —
 *
 *     actual_income = net_income - (payables - held_for_partners
 *                                            - payables_already_costed)
 *
 * — less what is owed but never the fleet's (partners' shares it holds for
 * them) and what is owed but already an expense above (a revenue-share
 * owner's cut, which is `owner_share_cents` on the sheet; payroll
 * withholdings; a serviced truck's unpaid garage bill). Each of those used to
 * come off twice: on ₱10,000 at a 15% cut the fleet keeps ₱1,500, and read
 * ₱1,500 − ₱8,500.
 *
 * which is the figure to look at before deciding anything can be drawn out. A
 * quarter that made ₱29,350 and owes ₱40,000 made ₱29,350 and is behind, and
 * only one of those two numbers says so.
 *
 * Profitability (a 10-day window) and Quarterly Summary (a quarter) are the
 * same roll-up over different date ranges, so they share one code path and
 * cannot disagree.
 */
class FinanceService
{
    public function __construct(
        private readonly LedgerRepository $ledger,
        private readonly ExpenseRepository $expenses,
        private readonly InvoiceRepository $invoices,
        private readonly PayablesService $payables,
        private readonly WalletService $wallet,
        private readonly ReceivablesService $receivables,
        private readonly FuelRepository $fuel,
        private readonly PayrollCostService $payroll,
    ) {}

    /** The workbook Table11 quarter boundaries, for a given year. */
    public function quarters(int $year): array
    {
        return [
            ['key' => 'q1', 'label' => '1st Quarter', 'from' => "$year-01-01", 'to' => "$year-03-31"],
            ['key' => 'q2', 'label' => '2nd Quarter', 'from' => "$year-04-01", 'to' => "$year-06-30"],
            ['key' => 'q3', 'label' => '3rd Quarter', 'from' => "$year-07-01", 'to' => "$year-09-30"],
            ['key' => 'q4', 'label' => '4th Quarter', 'from' => "$year-10-01", 'to' => "$year-12-31"],
        ];
    }

    /**
     * The workbook default view: ten days from a chosen start, **both ends
     * included** — the 1st to the 10th. `addDays(10)` made it the 1st to the
     * 11th, eleven days under a ten-day label.
     */
    public function tenDayRange(Carbon $from): array
    {
        return ['from' => $from->toDateString(), 'to' => $from->copy()->addDays(9)->toDateString()];
    }

    /**
     * One roll-up row per truck for the period.
     *
     * Every unit appears, including the two with no plate — filtering an idle
     * truck away would quietly change what "the fleet" means between pages.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pnlByTruck(Carbon $from, Carbon $to): array
    {
        $trucks = $this->ledger->trucks();
        $entries = $this->ledger->entriesBetween($from, $to)->groupBy('truck_id');
        // Categorised spend, which lives beside the five workbook columns
        // rather than inside them. A truck's real cost for the period is both.
        $lines = $this->expenses->between($from, $to)->groupBy('truck_id');
        // The fills logged at `/fuel` that no sheet row carries yet, by the
        // truck their vehicle is. A posted fill is already in `fuel_cents`.
        $fills = $this->fuelByTruck($from, $to, $trucks);
        // And the posted ones, only so a page can say how much of the Fuel
        // column came from `/fuel`.
        $posted = $this->fuel->postedBetween($from, $to)->groupBy('posted_truck_id');

        $rows = $trucks->map(function (Truck $truck) use ($entries, $lines, $fills, $posted): array {
            /** @var Collection<int, LedgerEntry> $mine */
            $mine = $entries->get($truck->id, collect());

            $income = (int) $mine->sum('trip_income_cents');
            $columns = (int) $mine->sum(fn (LedgerEntry $e): int => $e->totalExpensesCents());
            $other = (int) $lines->get($truck->id, collect())->sum('amount_cents');
            $unposted = (int) $fills->get($truck->id, collect())->sum('amount_cents');
            $logged = $unposted + (int) $posted->get($truck->id, collect())->sum('posted_cents');
            $expenses = $columns + $other + $unposted;

            return [
                'truck' => [
                    'id' => $truck->id,
                    'label' => $truck->label,
                    'plate' => $truck->plate,
                ],
                'trip_income_cents' => $income,
                // The sheet's column — which already holds every posted fill —
                // plus any fill no row carries yet, so the Fuel column is the
                // unit's whole fuel bill and each receipt is in it once.
                'fuel_cents' => (int) $mine->sum('fuel_cents') + $unposted,
                // How much of that came from `/fuel`, for a page to say so.
                'fuel_log_cents' => $logged,
                'driver_salary_cents' => (int) $mine->sum('driver_salary_cents'),
                'helper_salary_cents' => (int) $mine->sum('helper_salary_cents'),
                'maintenance_cents' => (int) $mine->sum('maintenance_cents'),
                'allowance_cents' => (int) $mine->sum('allowance_cents'),
                // What the owners of hired trucks took out. Zero for a fleet
                // that runs only its own.
                'owner_share_cents' => (int) $mine->sum('owner_share_cents'),
                // The categorised lines, kept as their own figure so a page can
                // show what the five columns never had a place for.
                'other_expenses_cents' => $other,
                'total_expenses_cents' => $expenses,
                'net_income_cents' => $income - $expenses,
                'net_share' => 0.0,
                'entry_count' => $mine->count(),
            ];
        })->all();

        // The "% OF NET INCOME" column: each truck over the fleet total. Shares
        // sum to 1, and a loss-maker carries a negative one.
        $totalNet = array_sum(array_column($rows, 'net_income_cents'));
        foreach ($rows as $i => $row) {
            $rows[$i]['net_share'] = $totalNet === 0 ? 0.0 : $row['net_income_cents'] / $totalNet;
        }

        return $rows;
    }

    /**
     * The period totals tile.
     *
     * `$overheadCents` is the spend that belongs to the period but to no truck
     * — office rent, an annual permit, a bulk tyre order. It cannot come out of
     * `$rows`, because no truck row contains it, and leaving it out entirely
     * would have the fleet look more profitable than the business is. So it is
     * charged to the period here and shown as its own line, which is also the
     * honest presentation: it is a real cost that no unit earned.
     *
     * `$supplierBillsCents` is the same idea for money paid out on bills from
     * suppliers: it belongs to the period and to no truck, and leaving it out
     * is what made a period's net income read higher than the bank did.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function periodTotals(
        array $rows,
        int $overheadCents = 0,
        int $supplierBillsCents = 0,
        int $payablesCents = 0,
        int $truckerPayoutsCents = 0,
        int $receivablesCents = 0,
        int $truckerCommissionCents = 0,
        int $vatCollectedCents = 0,
        int $heldForPartnersCents = 0,
        int $payrollCents = 0,
        int $otherIncomeCents = 0,
        int $payablesAlreadyCostedCents = 0,
    ): array {
        $sum = static fn (string $key): int => (int) array_sum(array_column($rows, $key));

        $tripIncome = $sum('trip_income_cents');
        // The fleet's income: its own trucks' takings, its cut of the
        // partners' runs, and what it billed by hand. A partner's share of a
        // run is theirs, passing through — see "Income from partners" at the
        // top of this class.
        $income = $tripIncome + $truckerCommissionCents + $otherIncomeCents;
        $expenses = $sum('total_expenses_cents')
            + $overheadCents
            + $supplierBillsCents
            + $payrollCents;

        return [
            // The company trucks' takings, as the daily sheet has them. The
            // truck rows add up to this and to nothing more.
            'trip_income_cents' => $tripIncome,
            // The fleet's commission on partners' runs delivered in the window.
            // In no truck row: a partner's truck is not on the sheet.
            'trucker_commission_cents' => $truckerCommissionCents,
            /**
             * Receivable invoices raised by hand — no trip behind them — at
             * their net. Their VAT was already in `vat_collected_cents` with
             * no income beside it, which read as tax on nothing. In no truck
             * row: the invoice names no unit.
             *
             * An office that also keys the same job onto the sheet by hand
             * counts it twice; a manual invoice is for work the sheet does
             * not carry.
             */
            'other_income_cents' => $otherIncomeCents,
            'total_income_cents' => $income,
            // VAT charged to customers on invoices raised in the window. The
            // government's, not income — shown so a reader can see what of the
            // money coming in is not the fleet's to keep.
            'vat_collected_cents' => $vatCollectedCents,
            'fuel_cents' => $sum('fuel_cents'),
            'fuel_log_cents' => $sum('fuel_log_cents'),
            'driver_salary_cents' => $sum('driver_salary_cents'),
            'helper_salary_cents' => $sum('helper_salary_cents'),
            'maintenance_cents' => $sum('maintenance_cents'),
            'allowance_cents' => $sum('allowance_cents'),
            'owner_share_cents' => $sum('owner_share_cents'),
            'other_expenses_cents' => $sum('other_expenses_cents'),
            'overhead_cents' => $overheadCents,
            // Bills from suppliers, at what was actually paid out over the
            // window. In the total and in no truck row, like the overhead.
            'supplier_bills_cents' => $supplierBillsCents,
            // Pay runs paid in the window, beyond the crew pay the sheet
            // already holds — see `PayrollCostService`. In no truck row: a
            // payslip is a person's fortnight, not a unit's day.
            'payroll_cents' => $payrollCents,
            // Partners' shares handed over and landed in the window. Shown, and
            // **not** an expense: it was their money, passing through, and the
            // fleet's income on those runs is already only its commission.
            'trucker_payouts_cents' => $truckerPayoutsCents,
            'total_expenses_cents' => $expenses,
            'net_income_cents' => $income - $expenses,

            /**
             * What is still owed, and what that leaves.
             *
             * Deliberately outside `total_expenses_cents` and outside
             * `net_income_cents` — an unpaid bill has cost the period nothing,
             * and folding it into the expenses would charge the quarter for
             * money that has not moved and charge it again the day it does.
             * `actual_income_cents` is the subtraction stated once here, so no
             * screen does it for itself and gets a different answer.
             */
            'payables_cents' => $payablesCents,
            // Of that, partners' shares the fleet is holding for them — owed,
            // but never the fleet's, so not taken off its income again.
            'held_for_partners_cents' => $heldForPartnersCents,
            // And of it, what the expenses above already carry — a
            // revenue-share owner's cut, payroll withholdings, a serviced
            // truck's unpaid garage bill. Owed, but already charged once, so
            // not taken off again. See `PayablesService::alreadyCostedAsOf()`.
            'payables_already_costed_cents' => $payablesAlreadyCostedCents,
            'actual_income_cents' => $income - $expenses
                - ($payablesCents - $heldForPartnersCents - $payablesAlreadyCostedCents),
            /**
             * What customers still owed the fleet at the close. Shown, and
             * changes nothing. The fleet's part of it — its own trucks' net
             * takings, its commission, its manual invoices — is already in the
             * income above, because income is booked when the work is done
             * rather than when it is paid. A partner's share inside a brokered
             * invoice is **not** in the income (it is theirs, passing through)
             * and is not the fleet's even once collected; nor is the VAT. See
             * `ReceivablesService`.
             */
            'receivables_cents' => $receivablesCents,
            'margin' => $income === 0 ? null : ($income - $expenses) / $income,
        ];
    }

    /**
     * Counted spend in the window that belongs to no single unit.
     *
     * The office's own lines, and the fills logged for a vehicle no truck on
     * the sheet points at.
     */
    public function overheadCents(Carbon $from, Carbon $to): int
    {
        $lines = (int) $this->expenses->between($from, $to)
            ->whereNull('truck_id')
            ->sum('amount_cents');

        $fills = (int) $this->fuelByTruck($from, $to, $this->ledger->trucks())
            ->get('', collect())
            ->sum('amount_cents');

        return $lines + $fills;
    }

    /**
     * The window's counted fills that no sheet row carries, grouped by the
     * truck whose vehicle they name.
     *
     * Posted fills are left out: they are already inside a row's `fuel_cents`.
     * The key is `''` for a fill whose vehicle no truck points at, which is
     * how `overheadCents()` finds them.
     *
     * @param  Collection<int, Truck>  $trucks
     * @return Collection<string, Collection<int, FuelRecord>>
     */
    private function fuelByTruck(Carbon $from, Carbon $to, Collection $trucks): Collection
    {
        $truckOf = $trucks->whereNotNull('vehicle_id')->pluck('id', 'vehicle_id');

        return $this->fuel->unpostedBetween($from, $to)
            ->groupBy(static fn (FuelRecord $fill): string => (string) ($truckOf[$fill->vehicle_id] ?? ''));
    }

    /**
     * What the fleet actually paid its suppliers over the window.
     *
     * Payments dated inside it, allocated to payable invoices — see the note at
     * the top of this class for why it is the payment's date and not the
     * bill's, and why a bill nobody has paid counts nothing.
     */
    public function supplierBillsPaidCents(Carbon $from, Carbon $to): int
    {
        return (int) $this->supplierBillSettlements($from, $to)->sum('amount_cents');
    }

    /**
     * The payments behind `supplierBillsPaidCents()`, one allocation each.
     *
     * **Less the garage bills a maintenance job already carries.** A costed
     * job posts its cost to the sheet's `maintenance_cents`; the garage's bill
     * for the same work, paid, was then counted again here — one oil change,
     * two expenses. A bill a job names (`maintenance_jobs.invoice_id`) is that
     * job's cost, so while the job has posted, paying it moves money that is
     * already on the books and adds nothing. Link only the bill for that work:
     * a bill that also covers tyres should be two bills, or the tyres filed as
     * their own expense.
     *
     * @return Collection<int, PaymentAllocation>
     */
    public function supplierBillSettlements(Carbon $from, Carbon $to): Collection
    {
        $serviced = MaintenanceJob::query()
            ->whereNotNull('invoice_id')
            ->where('posted_cents', '>', 0)
            ->pluck('invoice_id')
            ->unique()
            ->all();

        return $this->invoices->settlementsBetween(InvoiceDirection::Payable, $from, $to)
            ->reject(static fn (PaymentAllocation $allocation): bool => in_array($allocation->invoice_id, $serviced, true))
            ->values();
    }

    /**
     * Receivable invoices raised by hand in the window — no trip behind them.
     *
     * A delivered run's invoice is already income on the sheet (or, for a
     * partner's run, only its commission is), so those are left alone. What
     * is left is work the office billed directly: a charter keyed straight
     * into Billing, a storage fee. Their net is the fleet's income; their VAT
     * is already in `vat_collected_cents`. Dated by the day they were issued,
     * like that VAT, and a cancelled one charged nobody anything.
     *
     * @return Collection<int, Invoice>
     */
    public function manualInvoicesBetween(Carbon $from, Carbon $to): Collection
    {
        return Invoice::query()
            ->with('customer:id,name')
            ->where('direction', InvoiceDirection::Receivable->value)
            ->whereNull('trip_id')
            ->where('status', '!=', StatusValue::Cancelled->value)
            ->whereDate('issued_at', '>=', $from->toDateString())
            ->whereDate('issued_at', '<=', $to->toDateString())
            ->get();
    }

    /** An invoice's net, from the frozen breakdown where it has one. */
    public static function invoiceNetCents(Invoice $invoice): int
    {
        return $invoice->net_amount_cents !== null
            ? (int) $invoice->net_amount_cents
            : (int) $invoice->amount_cents - (int) $invoice->vat_cents;
    }

    /** The same, as one figure. */
    public function otherIncomeCents(Carbon $from, Carbon $to): int
    {
        return (int) $this->manualInvoicesBetween($from, $to)
            ->sum(static fn (Invoice $invoice): int => self::invoiceNetCents($invoice));
    }

    /**
     * The whole period, assembled once.
     *
     * Every screen reporting a period's income reads this — Profitability over
     * ten days, the Quarterly Summary over a quarter, the dashboard over
     * thirty. They used to assemble it themselves out of the pieces above, and
     * the dashboard quietly left the overhead out, so its net income was a
     * different figure from the summary's for the same days. One method, and
     * that cannot happen.
     *
     * @return array{trucks: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function periodRollup(Carbon $from, Carbon $to): array
    {
        $rows = $this->pnlByTruck($from, $to);

        return [
            'trucks' => $rows,
            'totals' => $this->periodTotals(
                $rows,
                $this->overheadCents($from, $to),
                $this->supplierBillsPaidCents($from, $to),
                // What was still owed as the window closed. Not an expense of
                // it — see `periodTotals()` — but the figure that says whether
                // the period's profit is money anybody can actually draw.
                $this->payables->outstandingAsOf($to),
                // What was handed to partners and landed inside it — shown,
                // not an expense (it was theirs).
                $this->wallet->paidOutBetween($from, $to),
                // And the other direction: what was still owed to the fleet.
                $this->receivables->outstandingAsOf($to),
                // The fleet's cut of partners' runs — its income from them.
                $this->wallet->commissionEarnedBetween($from, $to),
                // VAT charged on the window's invoices, beside the income.
                $this->invoices->vatRaisedBetween($from, $to),
                // Partners' money held for them at the close.
                $this->wallet->heldForPartnersAsOf($to),
                // Pay runs paid inside it, beyond the sheet's crew columns.
                $this->payroll->costBetween($from, $to),
                // Receivable invoices raised by hand, at their net.
                $this->otherIncomeCents($from, $to),
                // What is owed at the close and already an expense above.
                $this->payables->alreadyCostedAsOf($to),
            ),
        ];
    }

    /* -------------------------------------------------------------- Sales */

    /**
     * Sales over time, bucketed by day, week or month.
     *
     * Built from `ledger_entries.trip_income_cents` rather than from the trips
     * themselves, and that choice is the whole point. Trip income reaches the
     * ledger from two places — a delivered run credits it automatically, and
     * the office keys in the days it books by hand — so the ledger is the only
     * source that has all of the business's takings in it. Summing `trips`
     * instead would give a tidier query and a smaller number, and Sales would
     * disagree with Profitability and the Quarterly Summary, which read this.
     *
     * Bucketing happens in PHP, not SQL. Week and month boundaries differ
     * between sqlite and MySQL (`strftime` versus `YEARWEEK`, and they disagree
     * about which day starts a week), and this runs on both.
     *
     * @param  'daily'|'weekly'|'monthly'  $granularity
     * @return array<string, mixed>
     */
    public function sales(string $granularity, Carbon $from, Carbon $to): array
    {
        $entries = $this->ledger->entriesBetween($from, $to);
        $lines = $this->expenses->between($from, $to);
        // Only the fills no row carries: a posted one is inside its row's
        // `fuel_cents`, which the entries below already count.
        $fills = $this->fuel->unpostedBetween($from, $to);
        $bills = $this->supplierBillSettlements($from, $to);
        $commissions = $this->wallet->commissionsEarnedBetween($from, $to);
        $payroll = $this->payroll->paidBetween($from, $to);
        $manual = $this->manualInvoicesBetween($from, $to);

        $buckets = [];

        foreach ($entries as $entry) {
            $key = $this->bucketKey($granularity, $entry->date);

            $buckets[$key] ??= $this->emptyBucket($granularity, $entry->date);
            $buckets[$key]['sales_cents'] += $entry->trip_income_cents;
            $buckets[$key]['expenses_cents'] += $entry->totalExpensesCents();
            $buckets[$key]['entry_count']++;
        }

        // Expenses are bucketed too, so a period's net is right even where the
        // spend landed on a day with no takings — which is most Sundays.
        foreach ($lines as $line) {
            $key = $this->bucketKey($granularity, $line->date);

            $buckets[$key] ??= $this->emptyBucket($granularity, $line->date);
            $buckets[$key]['expenses_cents'] += $line->amount_cents;
        }

        // The Fuel module's fills, on the day they were logged. Every counted
        // one, truck or not — the series is the whole period, like the tile.
        foreach ($fills as $fill) {
            $key = $this->bucketKey($granularity, $fill->logged_at);

            $buckets[$key] ??= $this->emptyBucket($granularity, $fill->logged_at);
            $buckets[$key]['expenses_cents'] += $fill->amount_cents;
        }

        // And what went to suppliers, on the day it went. Bucketed here rather
        // than added to the totals at the end, so the series and its total say
        // the same thing and a reader can see which week the money left in.
        foreach ($bills as $bill) {
            $paidOn = $bill->payment?->paid_on;

            $key = $this->bucketKey($granularity, $paidOn);

            $buckets[$key] ??= $this->emptyBucket($granularity, $paidOn);
            $buckets[$key]['expenses_cents'] += $bill->amount_cents;
        }

        // The fleet's cut of partners' runs, on the day each was delivered —
        // its income from them. What it handed the partners is not a cost:
        // that was their money. See "Income from partners" above.
        foreach ($commissions as $entry) {
            $key = $this->bucketKey($granularity, $entry->occurred_on);

            $buckets[$key] ??= $this->emptyBucket($granularity, $entry->occurred_on);
            $buckets[$key]['sales_cents'] += (int) ($entry->trip?->commission_cents ?? 0);
        }

        // Payroll on its pay date, at what the roll-up counts of it.
        foreach ($payroll as $run) {
            $paidOn = Carbon::parse($run['date']);
            $key = $this->bucketKey($granularity, $paidOn);

            $buckets[$key] ??= $this->emptyBucket($granularity, $paidOn);
            $buckets[$key]['expenses_cents'] += $run['counted_cents'];
        }

        // Work billed by hand, on the day it was invoiced, at its net.
        foreach ($manual as $invoice) {
            $key = $this->bucketKey($granularity, $invoice->issued_at);

            $buckets[$key] ??= $this->emptyBucket($granularity, $invoice->issued_at);
            $buckets[$key]['sales_cents'] += self::invoiceNetCents($invoice);
        }

        ksort($buckets);

        $series = array_values(array_map(static function (array $bucket): array {
            $bucket['net_cents'] = $bucket['sales_cents'] - $bucket['expenses_cents'];

            return $bucket;
        }, $buckets));

        $sales = (int) array_sum(array_column($series, 'sales_cents'));
        $expenses = (int) array_sum(array_column($series, 'expenses_cents'));

        return [
            'granularity' => $granularity,
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'series' => $series,
            'totals' => [
                'sales_cents' => $sales,
                'expenses_cents' => $expenses,
                'net_cents' => $sales - $expenses,
                'margin' => $sales === 0 ? null : ($sales - $expenses) / $sales,
                // Averaged over the buckets that traded, not over the calendar:
                // dividing by every quiet day would flatter nothing and hide
                // what a working day is actually worth.
                'average_cents' => $series === [] ? 0 : (int) round($sales / max(1, count(array_filter(
                    $series,
                    static fn (array $bucket): bool => $bucket['sales_cents'] !== 0,
                )))),
                'best' => $this->bestBucket($series),
            ],
            'currency' => 'PHP',
        ];
    }

    /** The sort key and identity of a bucket. Sortable as a string. */
    private function bucketKey(string $granularity, ?CarbonInterface $date): string
    {
        $date = $date instanceof CarbonInterface ? $date : Carbon::now();

        return match ($granularity) {
            'monthly' => $date->format('Y-m'),
            // ISO week, so a year boundary mid-week does not split a bucket in
            // two and sort them apart.
            'weekly' => $date->copy()->startOfWeek()->format('o-\WW'),
            default => $date->toDateString(),
        };
    }

    /** @return array<string, mixed> */
    private function emptyBucket(string $granularity, ?CarbonInterface $date): array
    {
        $date = $date instanceof CarbonInterface ? $date : Carbon::now();

        [$start, $end, $label] = match ($granularity) {
            'monthly' => [
                $date->copy()->startOfMonth(),
                $date->copy()->endOfMonth(),
                $date->format('F Y'),
            ],
            'weekly' => [
                $date->copy()->startOfWeek(),
                $date->copy()->endOfWeek(),
                'Week of '.$date->copy()->startOfWeek()->format('j M Y'),
            ],
            default => [$date->copy(), $date->copy(), $date->format('j M Y')],
        };

        return [
            'key' => $this->bucketKey($granularity, $date),
            'label' => $label,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'sales_cents' => 0,
            'expenses_cents' => 0,
            'entry_count' => 0,
        ];
    }

    /**
     * The best bucket in the series, or null when nothing was earned.
     *
     * Null rather than the first bucket: "best day: 1 March, ₱0" is a lie the
     * office would read as a data problem.
     *
     * @param  array<int, array<string, mixed>>  $series
     * @return array<string, mixed>|null
     */
    private function bestBucket(array $series): ?array
    {
        $earning = array_filter($series, static fn (array $b): bool => $b['sales_cents'] > 0);

        if ($earning === []) {
            return null;
        }

        usort($earning, static fn (array $a, array $b): int => $b['sales_cents'] <=> $a['sales_cents']);

        return $earning[0];
    }

    /**
     * Did this unit actually trade? A scheduled row with a route but no money
     * is not activity, and counting it would dilute the average.
     *
     * @param  array<string, mixed>  $row
     */
    public function hasActivity(array $row): bool
    {
        return $row['trip_income_cents'] !== 0 || $row['total_expenses_cents'] !== 0;
    }

    /**
     * "AVE. PROFIT PER TRUCK" — net income across the units that traded.
     * Dividing by all eight would flatter idle trucks into the average.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{cents: int, trucks: int}
     */
    public function averageProfitPerTruck(array $rows): array
    {
        $active = array_values(array_filter($rows, fn (array $r): bool => $this->hasActivity($r)));

        if ($active === []) {
            return ['cents' => 0, 'trucks' => 0];
        }

        $net = array_sum(array_column($active, 'net_income_cents'));

        return ['cents' => (int) round($net / count($active)), 'trucks' => count($active)];
    }

    /**
     * "Best Performing Truck". Null when nobody is in profit — three of six
     * units are underwater in the seed period, so this really can be empty.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    public function bestPerformer(array $rows): ?array
    {
        $earning = array_filter($rows, static fn (array $r): bool => $r['net_income_cents'] > 0);

        if ($earning === []) {
            return null;
        }

        usort($earning, static fn (array $a, array $b): int => $b['net_income_cents'] <=> $a['net_income_cents']);

        return $earning[0];
    }

    public function createEntry(LedgerEntryData $data, ?int $userId): LedgerEntry
    {
        // A day whose fuel `/fuel` has already put on the sheet does not take
        // a typed figure beside it — that is the double count posting ends.
        if ((int) ($data->fuel_cents ?? 0) !== 0 && $data->truck_id !== null && $data->date !== null
            && $this->fillsPostedTo($data->truck_id, Carbon::parse($data->date))) {
            throw ValidationException::withMessages([
                'fuel_cents' => 'Fuel for this truck on this day is logged at Fuel Monitoring. Log the fill there instead of typing it on the sheet.',
            ]);
        }

        return DB::transaction(function () use ($data, $userId): LedgerEntry {
            $entry = $this->ledger->create($data->recordedBy($userId));
            $this->writeHelpers($entry, $data);

            return $entry->refresh();
        });
    }

    public function updateEntry(LedgerEntry $entry, LedgerEntryData $data): LedgerEntry
    {
        $this->refuseEditsToPostedFigures($entry, $data);

        return DB::transaction(function () use ($entry, $data): LedgerEntry {
            $updated = $this->ledger->update($entry, $data);
            $this->writeHelpers($updated, $data);

            return $updated->refresh();
        });
    }

    /**
     * Refuse to overwrite a figure something else posted.
     *
     * Three things post into a sheet row by **difference** — a delivered run
     * its income, a maintenance job its cost, a fill at `/fuel` its amount —
     * and each remembers what it put where, so it can take exactly that back
     * off later. A hand edit that rewrote `maintenance_cents` to a new
     * absolute figure, or moved the row to another day, broke that memory: the
     * job's next correction subtracted ₱3,200 from a column that no longer held
     * it, or from a day the money had left, and the sheet drifted from every
     * record behind it without anybody noticing.
     *
     * So the posted column, the day and the truck are the source's to change,
     * not the sheet's: edit the job, the trip or the fill, and the row follows.
     * Everything else on the row — the salaries, the allowance, the route —
     * stays the office's.
     */
    private function refuseEditsToPostedFigures(LedgerEntry $entry, LedgerEntryData $data): void
    {
        $posted = $this->postedOn($entry);

        if (! in_array(true, $posted, true)) {
            return;
        }

        $changes = static fn (string $key, mixed $current): bool => $data->wasGiven($key)
            && (string) ($data->{$key} ?? '') !== (string) ($current ?? '');

        $errors = [];

        if ($changes('date', $entry->date?->toDateString())
            && Carbon::parse((string) $data->date)->toDateString() !== $entry->date?->toDateString()) {
            $errors['date'] = 'This day carries figures posted from a trip, a maintenance job or a fuel fill. Change the date on that record instead.';
        }

        if ($changes('truck_id', $entry->truck_id)) {
            $errors['truck_id'] = 'This day carries figures posted from a trip, a maintenance job or a fuel fill. Move that record to the other unit instead.';
        }

        if ($posted['maintenance'] && $changes('maintenance_cents', (int) $entry->maintenance_cents)) {
            $errors['maintenance_cents'] = 'Maintenance on this day was posted from a service job. Correct the job under Truck Maintenance.';
        }

        if ($posted['trip_income'] && $changes('trip_income_cents', (int) $entry->trip_income_cents)) {
            $errors['trip_income_cents'] = 'Income on this day was posted from a delivered trip. Correct the trip instead.';
        }

        if ($posted['fuel'] && $changes('fuel_cents', (int) $entry->fuel_cents)) {
            $errors['fuel_cents'] = 'Fuel for this day is logged at Fuel Monitoring. Correct the fill there.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Which of the three posting sources have put money on this row.
     *
     * @return array{maintenance: bool, trip_income: bool, fuel: bool}
     */
    public function postedOn(LedgerEntry $entry): array
    {
        $day = $entry->date?->toDateString();
        $vehicleId = Truck::query()->whereKey($entry->truck_id)->value('vehicle_id');

        if ($day === null) {
            return ['maintenance' => false, 'trip_income' => false, 'fuel' => false];
        }

        return [
            'maintenance' => $vehicleId !== null && MaintenanceJob::query()
                ->where('vehicle_id', $vehicleId)
                ->where('posted_cents', '>', 0)
                ->whereDate('completed_on', $day)
                ->exists(),
            // A run delivered on this unit that day, billed and priced — the
            // one kind of trip `creditTripIncome()` posts for.
            'trip_income' => $vehicleId !== null && Trip::query()
                ->where('vehicle_id', $vehicleId)
                ->whereNull('trucker_id')
                ->whereNotNull('billed_at')
                ->where('price_cents', '>', 0)
                ->whereDate('billed_at', $day)
                ->exists(),
            'fuel' => $this->fillsPostedTo((string) $entry->truck_id, $entry->date),
        ];
    }

    /** Has any fill at `/fuel` posted onto this truck's row for the day? */
    private function fillsPostedTo(string $truckId, CarbonInterface $date): bool
    {
        return FuelRecord::query()
            ->where('posted_truck_id', $truckId)
            ->where('posted_cents', '>', 0)
            ->whereDate('posted_on', $date->toDateString())
            ->exists();
    }

    /**
     * The day's helper lines, from whichever of the two shapes arrived.
     *
     * `helpers` is the list, and replaces what was there. A bare
     * `helper_salary_cents` is what an app built before the lines still sends
     * — one figure for everybody — and it is honoured where it cannot be
     * misread: a day with one helper or none takes it as that helper's pay. A
     * day with several has nowhere to put one number without inventing a split,
     * so it is refused with the reason rather than guessed at.
     *
     * Saying neither leaves the lines alone.
     */
    private function writeHelpers(LedgerEntry $entry, LedgerEntryData $data): void
    {
        if ($data->wasGiven('helpers')) {
            $this->syncHelperLines($entry, $data->helpers ?? []);

            return;
        }

        if (! $data->wasGiven('helper_salary_cents')) {
            return;
        }

        $existing = $entry->helpers()->get();

        if ($existing->count() > 1) {
            throw ValidationException::withMessages([
                'helper_salary_cents' => 'This day has more than one helper, each paid separately. '
                    .'Enter each helper’s pay on the sheet, or update the app.',
            ]);
        }

        $cents = (int) $data->helper_salary_cents;
        $driverId = $existing->first()?->driver_id;

        $this->syncHelperLines($entry, $cents === 0 && $driverId === null
            ? []
            : [['driver_id' => $driverId, 'salary_cents' => $cents]]);
    }

    /**
     * Replace the day's helper lines, and keep the column that sums them true.
     *
     * `helper_salary_cents` is what every roll-up reads, so it is written here
     * and nowhere else once lines exist — the two cannot drift, because there
     * is only one place that writes either.
     *
     * @param  array<int, array{driver_id: ?string, salary_cents: int}>  $lines
     */
    public function syncHelperLines(LedgerEntry $entry, array $lines): void
    {
        $entry->helpers()->delete();

        foreach (array_values($lines) as $position => $line) {
            $entry->helpers()->create([
                'driver_id' => $line['driver_id'] ?? null,
                'salary_cents' => (int) ($line['salary_cents'] ?? 0),
                'position' => $position,
            ]);
        }

        $entry->forceFill([
            'helper_salary_cents' => (int) array_sum(array_column($lines, 'salary_cents')),
        ])->save();

        $entry->unsetRelation('helpers');
    }

    /**
     * Remove a day — unless something posted money onto it.
     *
     * Deleting a row a delivered run, a service job or a fuel fill posted to
     * takes their figures with it while each of them still believes it is on
     * the sheet, and the next correction to any of them lands on a row that
     * is not there. The source is where the money is taken back off.
     */
    public function deleteEntry(LedgerEntry $entry): void
    {
        if (in_array(true, $this->postedOn($entry), true)) {
            abort(422, 'This day carries figures posted from a trip, a maintenance job or a fuel fill. Change or remove those records instead; the day follows them.');
        }

        $this->ledger->delete($entry);
    }

    /* ------------------------------------------------- Sync from operations */

    /**
     * Open the day's row for a unit that has just completed a run.
     *
     * This is what puts a delivered trip on the Trip Monitoring sheet. Before
     * it, the ledger only ever heard about a day if somebody recorded one, so
     * a trip could be delivered and Monitoring stay empty — the two pages had
     * no connection at all.
     *
     * What it does NOT do is invent money. The amounts stay at zero, because
     * DESIGN.md section 5.1 is explicit that income and expenses are entered
     * and only the totals are derived — and a trip carries no rate to derive
     * them from. The row is the sheet line waiting for its figures, with the
     * route and the trip already filled in.
     *
     * Keyed on truck and date, so a unit running three trips in a day still
     * has one row, as the workbook does. The first delivery opens it and the
     * rest find it already there.
     *
     * Deliberately takes ids and strings rather than a `Trip`: Finance owns
     * the money and should not have to know the shape of an operations model
     * to file a row.
     */
    public function openDailyRow(
        string $vehicleId,
        ?string $plate,
        string $tripId,
        string $route,
        CarbonInterface $date,
        ?string $customerId = null,
        ?string $driverId = null,
        /** @var string[] Everyone who rode along, each opened at no pay yet. */
        array $helperIds = [],
    ): LedgerEntry {
        $truck = $this->truckForVehicle($vehicleId, $plate);

        $row = $this->openDailyRowForTruck($truck->id, $date, [
            'trip_id' => $tripId,
            // Carried from the trip, so the day lands on that customer's
            // history without anybody keying it in. A day covering more than
            // one customer keeps whoever's run opened it; the office can
            // correct it on the sheet.
            'customer_id' => $customerId,
            /**
             * Who was in the cab, carried from the trip for the same reason.
             *
             * The salary columns on this row have always recorded what the
             * crew was paid and never who they were, which was survivable
             * while the figure only fed Profitability — and stops being so the
             * moment somebody is paid from it. Like the customer, it names
             * whoever's run *opened* the day: a unit that changed crew keeps
             * the first, and the office can correct it on the sheet.
             */
            'driver_id' => $driverId,
            'route' => $route,
        ]);

        // A line per helper, at nothing yet — the amounts are entered, never
        // invented, but who they are owed to is known now. Only on a row this
        // call opened: one already on the sheet is somebody else's to change.
        if ($row->wasRecentlyCreated && $helperIds !== []) {
            $this->syncHelperLines($row, array_map(
                static fn (string $id): array => ['driver_id' => $id, 'salary_cents' => 0],
                array_values(array_unique($helperIds)),
            ));
        }

        return $row;
    }

    /**
     * The day's sheet for a unit, by truck rather than by vehicle.
     *
     * Extracted because a second caller arrived that has no vehicle to offer:
     * a categorised expense names the truck it was spent on directly, and
     * making Expenses look up a vehicle first — to look the truck straight back
     * up — would be a round trip for nothing.
     *
     * @param  array<string, mixed>  $attributes  what to fill a *new* row with
     */
    public function openDailyRowForTruck(string $truckId, CarbonInterface $date, array $attributes = []): LedgerEntry
    {
        // `whereDate` rather than an equality on the column: `date` is a
        // date-cast attribute, which Eloquent still writes through the model's
        // `Y-m-d H:i:s` format, so the stored value carries a midnight time.
        // Comparing it to a bare `Y-m-d` misses, and the day would be opened
        // again on every delivery.
        $existing = LedgerEntry::where('truck_id', $truckId)
            ->whereDate('date', $date->toDateString())
            ->first();

        // Found means the day is already on the sheet — from an earlier run,
        // or because somebody has recorded it. Either way it is not this
        // method's to touch: the figures in it are theirs.
        if ($existing !== null) {
            return $existing;
        }

        return LedgerEntry::create([
            ...$attributes,
            'truck_id' => $truckId,
            'date' => $date->toDateString(),
        ]);
    }

    /**
     * Put a delivered run's income on the day's row for its unit.
     *
     * This is the half of the sync that used to be missing. Delivering opened
     * the sheet line but left every figure at zero for somebody to type, so
     * the money only reached Profitability and the Quarterly Summary if a
     * human remembered — and a run nobody keyed in earned the business nothing
     * on its own books.
     *
     * `increment` rather than a write, for two reasons that both matter. A
     * unit running three hauls in a day keeps one row, as the workbook does,
     * and the day is worth all three; and it is additive against whatever the
     * office or the driver already entered, so recording the day by hand and
     * then delivering a second run adds to that figure instead of replacing
     * it. Expenses are still entirely theirs — nothing here touches fuel,
     * salary, maintenance or allowance, because a trip carries no knowledge of
     * what it cost to run.
     *
     * The caller is responsible for only calling this once per trip; `Trip`'s
     * `billed_at` is what makes that guarantee, because an additive credit is
     * exactly the kind that is wrong twice over if it runs twice.
     */
    public function creditTripIncome(
        string $vehicleId,
        ?string $plate,
        string $tripId,
        string $route,
        CarbonInterface $date,
        int $incomeCents,
        ?string $customerId = null,
        ?string $driverId = null,
        /** @var string[] */
        array $helperIds = [],
        /**
         * What the truck's owner took out of this run.
         *
         * Zero for the fleet's own units and for one hired at a flat monthly
         * rent — in both, every peso of the income is the fleet's. It is only
         * non-zero on a revenue-share truck, where the fleet keeps its cut and
         * the rest is a cost of having used somebody else's wheels.
         */
        int $ownerShareCents = 0,
    ): LedgerEntry {
        $row = $this->openDailyRow(
            vehicleId: $vehicleId,
            plate: $plate,
            tripId: $tripId,
            route: $route,
            date: $date,
            customerId: $customerId,
            driverId: $driverId,
            helperIds: $helperIds,
        );

        if ($incomeCents !== 0) {
            $row->increment('trip_income_cents', $incomeCents);
        }

        // Incremented rather than set, like the income above: a second run on
        // the same unit on the same day lands on the same sheet row, and both
        // owners' shares belong in the day's total.
        if ($ownerShareCents !== 0) {
            $row->increment('owner_share_cents', $ownerShareCents);
        }

        return $row->refresh();
    }

    /**
     * Put what a service cost onto the day's row for its unit.
     *
     * The maintenance half of the same sync `creditTripIncome()` does for
     * income: a job done on a truck is money that truck cost, and
     * `maintenance_cents` is the workbook column it has always belonged in.
     * Before this there was no way to get a figure into that column except by
     * typing it, so the service history and the sheet were two records of the
     * same event that nobody reconciled.
     *
     * **A delta, not a total.** The caller passes the difference between what
     * the job now says and what has already been pushed, so correcting ₱3,200
     * to ₱3,500 moves the sheet by ₱300 and saving the same job twice moves it
     * by nothing. `maintenance_jobs.posted_cents` is where that running total
     * lives; `MaintenanceService` is the only thing that should be computing
     * this argument.
     *
     * A zero delta opens no row. A vehicle whose job was costed at nothing —
     * a warranty replacement — should not conjure a sheet line for a day the
     * unit may not even have worked.
     */
    public function chargeMaintenance(
        string $vehicleId,
        ?string $plate,
        CarbonInterface $date,
        int $deltaCents,
    ): ?LedgerEntry {
        if ($deltaCents === 0) {
            return null;
        }

        $truck = $this->truckForVehicle($vehicleId, $plate);

        $row = $this->openDailyRowForTruck($truck->id, $date);

        // `increment` only takes a positive step, and a correction downwards is
        // an ordinary thing for somebody who mistyped a figure — so a negative
        // delta decrements, floored at zero. A column that went negative would
        // read as the garage having paid *us*.
        $deltaCents > 0
            ? $row->increment('maintenance_cents', $deltaCents)
            : $row->update([
                'maintenance_cents' => max(0, (int) $row->maintenance_cents + $deltaCents),
            ]);

        return $row->refresh();
    }

    /**
     * Put a fill logged at `/fuel` onto a truck's row for the day, or take it
     * back off.
     *
     * The fuel half of the same sync as `chargeMaintenance()`, and a delta in
     * the same way — `FuelPostingService` is the only caller, and it passes
     * exactly what it is putting on or taking off. By truck rather than by
     * vehicle, because a fill with no truck is overhead and must not open a
     * sheet; the service has already decided which truck, if any.
     */
    public function chargeFuel(string $truckId, CarbonInterface $date, int $deltaCents): ?LedgerEntry
    {
        if ($deltaCents === 0) {
            return null;
        }

        // Taking a fill off never opens a day: there is nothing to take it
        // off if the row is gone.
        $row = $deltaCents > 0
            ? $this->openDailyRowForTruck($truckId, $date)
            : LedgerEntry::where('truck_id', $truckId)->whereDate('date', $date->toDateString())->first();

        if ($row === null) {
            return null;
        }

        // Floored at zero like maintenance: a negative Fuel column would read
        // as the pump having paid us.
        $deltaCents > 0
            ? $row->increment('fuel_cents', $deltaCents)
            : $row->update(['fuel_cents' => max(0, (int) $row->fuel_cents + $deltaCents)]);

        return $row->refresh();
    }

    /**
     * The ledger sheet for a vehicle, created on first use.
     *
     * Matched on `vehicle_id` rather than on the plate: the plate is a label
     * that gets corrected and reformatted, and the workbook's own units 7 and
     * 8 have none at all.
     *
     * Creating one is not inventing a truck — the vehicle is already on the
     * fleet, and this is its sheet. Without it the first delivery for a new
     * unit would have nowhere to file, which is exactly the state that made
     * Monitoring look broken: a fleet with no sheets shows nothing whatever
     * happens on the road.
     */
    private function truckForVehicle(string $vehicleId, ?string $plate): Truck
    {
        $existing = Truck::where('vehicle_id', $vehicleId)->first();

        if ($existing !== null) {
            return $existing;
        }

        // Follows the workbook's naming — "Truck 1", "Truck 2" — with the
        // plate kept in its own column, where a unit without one renders as
        // "Unassigned" rather than dropping out of the tab strip.
        $position = (int) Truck::max('position') + 1;

        return Truck::create([
            'label' => "Truck {$position}",
            'plate' => $plate,
            'vehicle_id' => $vehicleId,
            'position' => $position,
        ]);
    }
}
