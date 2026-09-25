<?php

declare(strict_types=1);

namespace App\Domain\Finance\Controllers;

use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Finance\Repositories\LedgerRepository;
use App\Domain\Finance\Requests\LedgerEntryRequest;
use App\Domain\Finance\Requests\TruckRequest;
use App\Domain\Finance\Resources\LedgerEntryResource;
use App\Domain\Finance\Resources\TruckResource;
use App\Domain\Finance\Services\ExpenseLinesService;
use App\Domain\Finance\Services\FinanceService;
use App\Domain\Finance\Services\ReceivablesService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The three Finance modules — Daily Trip Monitoring, Profitability and
 * Quarterly Summary — over one ledger.
 *
 * Profitability and Summary are the same roll-up over different ranges, so
 * they are one method here with the range as the difference.
 */
class FinanceController extends ApiController
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly LedgerRepository $ledger,
        private readonly ExpenseLinesService $expenseLines,
        private readonly ReceivablesService $receivables,
    ) {}

    /** The units the workbook keeps a sheet per, unassigned ones included. */
    public function trucks(): JsonResponse
    {
        $trucks = $this->ledger->trucks();

        return $this->collection(TruckResource::collection($trucks), $trucks);
    }

    public function storeTruck(TruckRequest $request): JsonResponse
    {
        return $this->item(
            new TruckResource($this->ledger->createTruck($request->toData())),
            status: 201,
        );
    }

    public function updateTruck(TruckRequest $request, Truck $truck): JsonResponse
    {
        return $this->item(new TruckResource($this->ledger->updateTruck($truck, $request->toData())));
    }

    /**
     * Retiring a unit.
     *
     * Refused while it still has ledger rows: deleting it would take the
     * money with it, and a period that used to balance would quietly stop.
     */
    public function destroyTruck(Truck $truck): JsonResponse
    {
        abort_if(
            $truck->entries()->exists(),
            422,
            'This unit has ledger entries. Delete those first, or leave the unit in place.',
        );

        $truck->delete();

        return $this->noContent();
    }

    /** Daily Trip Monitoring: the rows themselves. */
    public function index(Request $request): JsonResponse
    {
        $page = $this->ledger->paginate($this->filters($request), $this->perPage($request, 100));

        return $this->collection(LedgerEntryResource::collection($page), $page);
    }

    public function store(LedgerEntryRequest $request): JsonResponse
    {
        $entry = $this->finance->createEntry($request->toData(), $request->user()?->id);

        return $this->item(new LedgerEntryResource($entry), status: 201);
    }

    public function update(LedgerEntryRequest $request, LedgerEntry $ledger): JsonResponse
    {
        return $this->item(new LedgerEntryResource($this->finance->updateEntry($ledger, $request->toData())));
    }

    public function destroy(LedgerEntry $ledger): JsonResponse
    {
        $this->finance->deleteEntry($ledger);

        return $this->noContent();
    }

    /** Routes already used, to suggest in the entry form. */
    public function routes(): JsonResponse
    {
        return $this->payload($this->ledger->knownRoutes());
    }

    /**
     * Sales by day, week or month.
     *
     * The default window follows the granularity, because the useful view of
     * each is a different length of history: a month of days, a quarter of
     * weeks, a year of months. A caller can always name its own range.
     */
    public function sales(Request $request): JsonResponse
    {
        $granularity = $request->string('granularity', 'daily')->toString();

        if (! in_array($granularity, ['daily', 'weekly', 'monthly'], true)) {
            $granularity = 'daily';
        }

        $to = $request->filled('to')
            ? Carbon::parse($request->string('to')->toString())
            : now()->endOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())
            : match ($granularity) {
                'monthly' => $to->copy()->subYear()->startOfMonth(),
                'weekly' => $to->copy()->subWeeks(12)->startOfWeek(),
                default => $to->copy()->subDays(29)->startOfDay(),
            };

        return $this->payload($this->finance->sales($granularity, $from, $to));
    }

    /**
     * Profitability — the workbook's 10-day window by default, or any range
     * the caller names.
     */
    public function profitability(Request $request): JsonResponse
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())
            : now()->subDays(10);

        $to = $request->filled('to')
            ? Carbon::parse($request->string('to')->toString())
            : $from->copy()->addDays(10);

        return $this->rollup($from, $to);
    }

    /** Quarterly Summary — the same roll-up over a quarter. */
    public function summary(Request $request): JsonResponse
    {
        $year = (int) $request->integer('year', (int) now()->year);
        $quarters = $this->finance->quarters($year);

        $key = $request->string('quarter', 'q'.now()->quarter)->toString();
        $quarter = collect($quarters)->firstWhere('key', $key) ?? $quarters[0];

        return $this->rollup(
            Carbon::parse($quarter['from']),
            Carbon::parse($quarter['to']),
            ['quarter' => $quarter, 'quarters' => $quarters, 'year' => $year],
        );
    }

    /**
     * The transactions behind a period's Total expenses.
     *
     * What the Summary tile opens. The window defaults to this quarter, the
     * Summary's own opening view; an unreadable date falls back to that
     * default, and ends given the wrong way round are swapped.
     */
    public function expenseLines(Request $request): JsonResponse
    {
        $from = $this->date($request, 'from') ?? now()->startOfQuarter();
        $to = $this->date($request, 'to') ?? now()->endOfQuarter()->startOfDay();

        [$from, $to] = $from->greaterThan($to) ? [$to, $from] : [$from, $to];

        return $this->payload($this->expenseLines->between($from, $to));
    }

    /**
     * What was owed to the fleet at a date, invoice by invoice.
     *
     * What the Summary's Receivables tile opens, asked about the close of the
     * quarter it shows. Today when no date, or an unreadable one, is given.
     */
    public function receivableLines(Request $request): JsonResponse
    {
        $asOf = $this->date($request, 'as_of') ?? now()->startOfDay();
        $lines = $this->receivables->linesAsOf($asOf);

        return $this->payload([
            'as_of' => $asOf->toDateString(),
            'lines' => $lines,
            'total_cents' => (int) array_sum(array_column($lines, 'amount_cents')),
            'currency' => 'PHP',
        ]);
    }

    /** A date off the query string, or null when it is missing or unreadable. */
    private function date(Request $request, string $key): ?Carbon
    {
        $value = trim((string) $request->query($key, ''));

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * One roll-up, used by both period views so they cannot drift apart.
     *
     * @param  array<string, mixed>  $meta
     */
    private function rollup(Carbon $from, Carbon $to, array $meta = []): JsonResponse
    {
        /**
         * One call, and the two figures that belong to no truck come with it.
         *
         * The overhead — office rent, an annual permit — and the supplier bills
         * actually paid over the window. Both reach the totals without
         * distorting any unit's profitability, and both used to be assembled
         * here, which is how the dashboard came to report a different net
         * income for the same days. See `FinanceService::periodRollup`.
         */
        $rollup = $this->finance->periodRollup($from, $to);
        $rows = $rollup['trucks'];

        return $this->payload(
            [
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'trucks' => $rows,
                'totals' => $rollup['totals'],
                'average_profit_per_truck' => $this->finance->averageProfitPerTruck($rows),
                // Null when nobody is in profit, which really does happen.
                'best_performer' => $this->finance->bestPerformer($rows),
                'currency' => 'PHP',
            ],
            $meta,
        );
    }
}
