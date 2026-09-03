<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\DTO\LedgerEntryData;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Finance\Repositories\ExpenseRepository;
use App\Domain\Finance\Repositories\LedgerRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The money side of the business — the server half of `core/finance.ts`.
 *
 * The two formulas from DESIGN.md section 5.1 live here and nowhere else:
 *
 *   total_expenses = fuel + driver_salary + helper_salary + maintenance
 *                    + allowance + categorised expense lines
 *   net_income     = trip_income - total_expenses
 *
 * The five columns are the transcribed workbook's; the lines are everything a
 * real day spends that those columns had no place for (`Expense`). They are
 * added, not reconciled — a fill-up keyed into `fuel_cents` *and* filed as a
 * Fuel line counts twice, because nothing here can tell that from a second
 * fill-up on the same day.
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
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function periodTotals(array $rows, int $overheadCents = 0): array
    {
        $sum = static fn (string $key): int => (int) array_sum(array_column($rows, $key));

        $income = $sum('trip_income_cents');
        $expenses = $sum('total_expenses_cents') + $overheadCents;

        return [
            'trip_income_cents' => $income,
            'fuel_cents' => $sum('fuel_cents'),
            'driver_salary_cents' => $sum('driver_salary_cents'),
            'helper_salary_cents' => $sum('helper_salary_cents'),
            'maintenance_cents' => $sum('maintenance_cents'),
            'allowance_cents' => $sum('allowance_cents'),
            'other_expenses_cents' => $sum('other_expenses_cents'),
            'overhead_cents' => $overheadCents,
            'total_expenses_cents' => $expenses,
            'net_income_cents' => $income - $expenses,
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
        return $this->ledger->create($data->recordedBy($userId));
    }

    public function updateEntry(LedgerEntry $entry, LedgerEntryData $data): LedgerEntry
    {
        return $this->ledger->update($entry, $data);
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
    ): LedgerEntry {
        $truck = $this->truckForVehicle($vehicleId, $plate);

        return $this->openDailyRowForTruck($truck->id, $date, [
            'trip_id' => $tripId,
            // Carried from the trip, so the day lands on that customer's
            // history without anybody keying it in. A day covering more than
            // one customer keeps whoever's run opened it; the office can
            // correct it on the sheet.
            'customer_id' => $customerId,
            'route' => $route,
        ]);
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
    ): LedgerEntry {
        $row = $this->openDailyRow(
            vehicleId: $vehicleId,
            plate: $plate,
            tripId: $tripId,
            route: $route,
            date: $date,
            customerId: $customerId,
        );

        if ($incomeCents !== 0) {
            $row->increment('trip_income_cents', $incomeCents);
        }

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
