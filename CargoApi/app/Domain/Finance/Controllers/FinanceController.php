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
use App\Domain\Finance\Services\FinanceService;
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
     * One roll-up, used by both period views so they cannot drift apart.
     *
     * @param  array<string, mixed>  $meta
     */
    private function rollup(Carbon $from, Carbon $to, array $meta = []): JsonResponse
    {
        $rows = $this->finance->pnlByTruck($from, $to);
        // Spend that belongs to the period but to no unit, so it reaches the
        // totals without distorting any truck's profitability.
        $overhead = $this->finance->overheadCents($from, $to);

        return $this->payload(
            [
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'trucks' => $rows,
                'totals' => $this->finance->periodTotals($rows, $overhead),
                'average_profit_per_truck' => $this->finance->averageProfitPerTruck($rows),
                // Null when nobody is in profit, which really does happen.
                'best_performer' => $this->finance->bestPerformer($rows),
                'currency' => 'PHP',
            ],
            $meta,
        );
    }
}
