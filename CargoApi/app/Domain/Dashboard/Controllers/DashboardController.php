<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Controllers;

use App\Domain\Dashboard\Services\DashboardService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The consolidated dashboard. Read-only and computed, so there is no model,
 * no DTO and no repository in this module — only a Service and this.
 */
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

    /**
     * Receivables and income — pending payment against successful payment.
     *
     * A fifth call rather than a field on `kpis`, for the reason the other
     * four are separate (DESIGN.md section 7.6): this one touches the invoice
     * table and the whole ledger, and a slow roll-up must not hold up the
     * tiles that were ready.
     *
     * Money comes back as centavos and a currency, never formatted — the
     * client decides how to read a peso.
     */
    public function receivables(Request $request): JsonResponse
    {
        // Clamped: a window of zero days is not a window, and one of ten years
        // is a report rather than a dashboard tile.
        $days = min(365, max(1, (int) $request->integer('days', 30)));

        return $this->payload($this->dashboard->receivables($days));
    }
}
