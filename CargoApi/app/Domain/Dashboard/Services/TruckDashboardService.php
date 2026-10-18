<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Services;

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Finance\Models\Truck;
use App\Domain\Finance\Services\TruckReportService;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Support\Carbon;

class TruckDashboardService
{
    public function __construct(private readonly TruckReportService $report) {}

    /** @return array<string, mixed> */
    public function overview(string $period, Carbon $date): array
    {
        $report = $this->report->report($period, $date);
        $from = Carbon::parse($report['range']['from'])->startOfDay();
        $to = Carbon::parse($report['range']['to'])->endOfDay();

        $vehicleOf = Truck::query()->pluck('vehicle_id', 'id');
        $status = Vehicle::query()->whereIn('id', $vehicleOf->filter()->values())->pluck('status', 'id');

        $delivered = DeliveryLog::query()
            ->where('status', StatusValue::Delivered->value)
            ->whereBetween('delivered_at', [$from, $to])
            ->with('trip:id,vehicle_id')
            ->get()
            ->groupBy(static fn (DeliveryLog $log): string => (string) $log->trip?->vehicle_id)
            ->map->count();

        $trucks = collect($report['trucks'])->map(static function (array $row) use ($vehicleOf, $status, $delivered): array {
            $vehicleId = $vehicleOf[$row['truck']['id']] ?? null;
            $vehicleStatus = $vehicleId === null ? null : ($status[$vehicleId] ?? null);

            return [
                'truck' => $row['truck'],
                'status' => $vehicleStatus instanceof \BackedEnum ? $vehicleStatus->value : $vehicleStatus,
                'trips' => $vehicleId === null ? 0 : (int) ($delivered[$vehicleId] ?? 0),
                'income_cents' => $row['income_cents'],
                'expenses_cents' => $row['expenses_cents'],
                'net_income_cents' => $row['net_income_cents'],
                'margin' => $row['income_cents'] === 0 ? null : $row['net_income_cents'] / $row['income_cents'],
                'top_costs' => array_slice($row['by_kind'], 0, 3),
            ];
        })->sortByDesc('net_income_cents')->values();

        return [
            'period' => $report['period'],
            'label' => $report['label'],
            'range' => $report['range'],
            'previous' => $report['previous'],
            'next' => $report['next'],
            'trucks' => $trucks->all(),
            'totals' => [
                ...$report['totals'],
                'trips' => (int) $trucks->sum('trips'),
            ],
            'currency' => 'PHP',
        ];
    }
}
