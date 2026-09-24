<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Billing\Repositories\InvoiceRepository;
use App\Domain\Finance\DTO\LedgerEntryData;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Finance\Repositories\ExpenseRepository;
use App\Domain\Finance\Repositories\LedgerRepository;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Trucker\Services\WalletService;
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
 *   total_expenses = fuel + driver_salary + helper_salary + maintenance
 *                    + allowance + categorised expense lines
 *                    + supplier bills actually paid
 *                    + payouts actually handed to partner truckers
 *   net_income     = trip_income - total_expenses
 *
 * The five columns are the transcribed workbook's; the lines are everything a
 * real day spends that those columns had no place for (`Expense`). They are
 * added, not reconciled — a fill-up keyed into `fuel_cents` *and* filed as a
 * Fuel line counts twice, because nothing here can tell that from a second
 * fill-up on the same day.
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
 *     actual_income = net_income - payables
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

    /** The workbook default view: ten days from a chosen start. */
    public function tenDayRange(Carbon $from): array
    {
        return ['from' => $from->toDateString(), 'to' => $from->copy()->addDays(10)->toDateString()];
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

        $rows = $trucks->map(function (Truck $truck) use ($entries, $lines): array {
            /** @var Collection<int, LedgerEntry> $mine */
            $mine = $entries->get($truck->id, collect());

            $income = (int) $mine->sum('trip_income_cents');
            $columns = (int) $mine->sum(fn (LedgerEntry $e): int => $e->totalExpensesCents());
            $other = (int) $lines->get($truck->id, collect())->sum('amount_cents');
            $expenses = $columns + $other;

            return [
                'truck' => [
                    'id' => $truck->id,
                    'label' => $truck->label,
                    'plate' => $truck->plate,
                ],
                'trip_income_cents' => $income,
                'fuel_cents' => (int) $mine->sum('fuel_cents'),
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
    ): array {
        $sum = static fn (string $key): int => (int) array_sum(array_column($rows, $key));

        $income = $sum('trip_income_cents');
        $expenses = $sum('total_expenses_cents')
            + $overheadCents
            + $supplierBillsCents
            + $truckerPayoutsCents;

        return [
            'trip_income_cents' => $income,
            'fuel_cents' => $sum('fuel_cents'),
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
            // Money handed to partner truckers and landed over the window. In
            // the total and in no truck row, like the two above — a partner's
            // run has no sheet of its own to charge it to.
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
            'actual_income_cents' => $income - $expenses - $payablesCents,
            // What customers still owed the fleet at the close. Already in the
            // trip income above, so it is shown and changes nothing — see
            // `ReceivablesService`.
            'receivables_cents' => $receivablesCents,
            'margin' => $income === 0 ? null : ($income - $expenses) / $income,
        ];
    }

    /** Counted spend in the window that belongs to no single unit. */
    public function overheadCents(Carbon $from, Carbon $to): int
    {
        return (int) $this->expenses->between($from, $to)
            ->whereNull('truck_id')
            ->sum('amount_cents');
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
        return $this->invoices->settledBetween(InvoiceDirection::Payable, $from, $to);
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
                // And what was handed to partners and landed inside it, which
                // is the other half of the same cash question.
                $this->wallet->paidOutBetween($from, $to),
                // And the other direction: what was still owed to the fleet.
                $this->receivables->outstandingAsOf($to),
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
        $bills = $this->invoices->settlementsBetween(InvoiceDirection::Payable, $from, $to);
        $payouts = $this->wallet->payoutsLandedBetween($from, $to);

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

        // And what went to suppliers, on the day it went. Bucketed here rather
        // than added to the totals at the end, so the series and its total say
        // the same thing and a reader can see which week the money left in.
        foreach ($bills as $bill) {
            $paidOn = $bill->payment?->paid_on;

            $key = $this->bucketKey($granularity, $paidOn);

            $buckets[$key] ??= $this->emptyBucket($granularity, $paidOn);
            $buckets[$key]['expenses_cents'] += $bill->amount_cents;
        }

        // And what was handed to partners, on the day it was handed over.
        // Stored negative, because a payout is money leaving; the series wants
        // a magnitude in its expenses column.
        foreach ($payouts as $payout) {
            $key = $this->bucketKey($granularity, $payout->occurred_on);

            $buckets[$key] ??= $this->emptyBucket($granularity, $payout->occurred_on);
            $buckets[$key]['expenses_cents'] += abs((int) $payout->amount_cents);
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
        return DB::transaction(function () use ($data, $userId): LedgerEntry {
            $entry = $this->ledger->create($data->recordedBy($userId));
            $this->writeHelpers($entry, $data);

            return $entry->refresh();
        });
    }

    public function updateEntry(LedgerEntry $entry, LedgerEntryData $data): LedgerEntry
    {
        return DB::transaction(function () use ($entry, $data): LedgerEntry {
            $updated = $this->ledger->update($entry, $data);
            $this->writeHelpers($updated, $data);

            return $updated->refresh();
        });
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

    public function deleteEntry(LedgerEntry $entry): void
    {
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
