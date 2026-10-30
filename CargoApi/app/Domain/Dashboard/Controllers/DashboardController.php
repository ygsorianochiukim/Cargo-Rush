<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Controllers;

use App\Domain\Dashboard\Services\BusinessDashboardService;
use App\Domain\Dashboard\Services\DashboardService;
use App\Domain\Dashboard\Services\TruckDashboardService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends ApiController
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function kpis(): JsonResponse
    {
        return $this->payload($this->dashboard->kpis());
    }

    public function assets(): JsonResponse
    {
        return $this->payload($this->dashboard->assets());
    }

    public function fleet(): JsonResponse
    {
        return $this->payload($this->dashboard->fleetBreakdown());
    }

    public function deliveries(): JsonResponse
    {
        return $this->payload($this->dashboard->deliveriesPerDay());
    }

    public function activity(): JsonResponse
    {
        return $this->payload($this->dashboard->activity());
    }

    public function business(Request $request, BusinessDashboardService $business): JsonResponse
    {
        return $this->payload($business->overview(
            $request->string('period', 'month')->toString(),
            $this->dateFrom($request),
        ));
    }

    public function trucks(Request $request, TruckDashboardService $trucks): JsonResponse
    {
        return $this->payload($trucks->overview(
            $request->string('period', 'month')->toString(),
            $this->dateFrom($request),
        ));
    }

    private function dateFrom(Request $request): Carbon
    {
        try {
            return $request->filled('date')
                ? Carbon::parse($request->string('date')->toString())->startOfDay()
                : now()->startOfDay();
        } catch (\Throwable) {
            return now()->startOfDay();
        }
    }

    public function receivables(Request $request): JsonResponse
    {
        $days = min(365, max(1, (int) $request->integer('days', 30)));

        return $this->payload($this->dashboard->receivables($days));
    }
}
