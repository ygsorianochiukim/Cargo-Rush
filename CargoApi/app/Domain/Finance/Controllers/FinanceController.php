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

class FinanceController extends ApiController
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly LedgerRepository $ledger,
        private readonly ExpenseLinesService $expenseLines,
        private readonly ReceivablesService $receivables,
    ) {}

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

    public function routes(): JsonResponse
    {
        return $this->payload($this->ledger->knownRoutes());
    }

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

    public function profitability(Request $request): JsonResponse
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())
            : now()->subDays(9)->startOfDay();

        $to = $request->filled('to')
            ? Carbon::parse($request->string('to')->toString())
            : Carbon::parse($this->finance->tenDayRange($from)['to']);

        return $this->rollup($from, $to);
    }

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

    public function expenseLines(Request $request): JsonResponse
    {
        $from = $this->date($request, 'from') ?? now()->startOfQuarter();
        $to = $this->date($request, 'to') ?? now()->endOfQuarter()->startOfDay();

        [$from, $to] = $from->greaterThan($to) ? [$to, $from] : [$from, $to];

        return $this->payload($this->expenseLines->between($from, $to));
    }

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

    /** @param array<string, mixed> $meta */
    private function rollup(Carbon $from, Carbon $to, array $meta = []): JsonResponse
    {
        $rollup = $this->finance->periodRollup($from, $to);
        $rows = $rollup['trucks'];

        return $this->payload(
            [
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'trucks' => $rows,
                'totals' => $rollup['totals'],
                'average_profit_per_truck' => $this->finance->averageProfitPerTruck($rows),
                'best_performer' => $this->finance->bestPerformer($rows),
                'currency' => 'PHP',
            ],
            $meta,
        );
    }
}
