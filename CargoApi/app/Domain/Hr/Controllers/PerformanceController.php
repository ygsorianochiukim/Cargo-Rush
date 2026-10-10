<?php

declare(strict_types=1);

namespace App\Domain\Hr\Controllers;

use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Services\PerformanceService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PerformanceController extends ApiController
{
    public function __construct(private readonly PerformanceService $performance) {}

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

    public function show(Request $request, Employee $employee): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return $this->payload($this->performance->forEmployee($employee, $from, $to));
    }

    /** @return array{0: Carbon, 1: Carbon} */
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
