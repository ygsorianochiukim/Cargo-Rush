<?php

declare(strict_types=1);

namespace App\Domain\Hr\Controllers;

use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Services\PerformanceService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Performance — trips completed, share on time, incidents, time away.
 *
 * Recomputed from the operational record on every call rather than stored.
 * A stored figure starts disagreeing with the trips underneath it the first
 * time a run is reassigned or a delivery is backdated, and then nobody can say
 * which number is right.
 */
class PerformanceController extends ApiController
{
    public function __construct(private readonly PerformanceService $performance) {}

    /** The roster ranked by runs completed. */
    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $rows = $this->performance->leaderboard($from, $to);

        return $this->payload([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'crew' => $rows,
            'totals' => $this->performance->totals($rows),
        ]);
    }

    /** One person's figures for the same window. */
    public function show(Request $request, Employee $employee): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return $this->payload($this->performance->forEmployee($employee, $from, $to));
    }

    /**
     * The window: the last 30 days unless the caller names one.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->string('to')->toString())
            : Carbon::now();

        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())
            : $to->copy()->subDays(29);

        return [$from, $to];
    }
}
