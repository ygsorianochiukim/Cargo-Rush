<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Controllers;

use App\Domain\Dashboard\Services\DashboardService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends ApiController
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function kpis(): JsonResponse
    {
        return $this->payload($this->dashboard->kpis());
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

    public function receivables(Request $request): JsonResponse
    {
        $days = min(365, max(1, (int) $request->integer('days', 30)));

        return $this->payload($this->dashboard->receivables($days));
    }
}
