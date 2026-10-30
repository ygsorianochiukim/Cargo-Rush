<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Services;

use App\Domain\Asset\Models\Asset;
use App\Domain\Billing\Repositories\InvoiceRepository;
use App\Domain\Delivery\Repositories\DeliveryLogRepository;
use App\Domain\Driver\Repositories\DriverRepository;
use App\Domain\Finance\Services\FinanceService;
use App\Domain\Incident\Repositories\IncidentRepository;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Trip\Repositories\TripRepository;
use App\Domain\Vehicle\Repositories\VehicleRepository;
use Illuminate\Support\Carbon;

class DashboardService
{
    private const FLEET_LABELS = [
        'active' => 'On the road',
        'available' => 'Idle at depot',
        'maintenance' => 'In maintenance',
        'inactive' => 'Out of service',
    ];

    public function __construct(
        private readonly TripRepository $trips,
        private readonly VehicleRepository $vehicles,
        private readonly DriverRepository $drivers,
        private readonly IncidentRepository $incidents,
        private readonly DeliveryLogRepository $deliveries,
        private readonly InvoiceRepository $invoices,
        private readonly FinanceService $finance,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function kpis(): array
    {
        $counts = $this->trips->countsByStatus();

        $active = ($counts[StatusValue::InTransit->value] ?? 0)
            + ($counts[StatusValue::Assigned->value] ?? 0);

        return [
            [
                'key' => 'active',
                'label' => 'Active trips',
                'value' => (string) $active,
                'delta' => null,
                'higher_is_better' => true,
            ],
            [
                'key' => 'on_time',
                'label' => 'On-time rate',
                'value' => $this->drivers->fleetOnTimeRate().'%',
                'delta' => null,
                'higher_is_better' => true,
            ],
            [
                'key' => 'utilisation',
                'label' => 'Fleet utilisation',
                'value' => round($this->vehicles->utilisation()).'%',
                'delta' => null,
                'higher_is_better' => true,
            ],
            [
                'key' => 'incidents',
                'label' => 'Open incidents',
                'value' => (string) $this->incidents->openCount(),
                'delta' => null,
                'higher_is_better' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function assets(): array
    {
        $byStatus = Asset::query()
            ->selectRaw('status, count(*) as items, coalesce(sum(cost_cents), 0) as cost_cents')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $count = fn (StatusValue $status): int => (int) ($byStatus[$status->value]->items ?? 0);

        return [
            'count' => (int) $byStatus->sum('items'),
            'cost_cents' => (int) $byStatus->sum('cost_cents'),
            'assigned' => $count(StatusValue::Assigned),
            'available' => $count(StatusValue::Available),
            'maintenance' => $count(StatusValue::Maintenance),
            'inactive' => $count(StatusValue::Inactive),
            'currency' => 'PHP',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function fleetBreakdown(): array
    {
        $counts = $this->vehicles->countsByStatus();

        return collect(self::FLEET_LABELS)
            ->map(fn (string $label, string $status): array => [
                'status' => $status,
                'label' => $label,
                'count' => $counts[$status] ?? 0,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function deliveriesPerDay(int $days = 7): array
    {
        $rows = $this->deliveries->deliveredPerDay($days)->keyBy('day');

        return collect(range($days - 1, 0))
            ->map(function (int $back) use ($rows): array {
                $date = now()->subDays($back);
                $key = $date->toDateString();

                return [
                    'day' => $date->format('D'),
                    'date' => $key,
                    'delivered' => (int) ($rows[$key]->delivered ?? 0),
                ];
            })
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function activity(int $limit = 8): array
    {
        $deliveries = $this->deliveries->query()
            ->whereNotNull('delivered_at')
            ->limit($limit)
            ->get()
            ->map(fn ($log): array => [
                'id' => 'delivery-'.$log->id,
                'icon' => 'clipboard',
                'title' => ($log->trip?->reference ?? 'A trip').' delivered',
                'detail' => trim('Signed by '.($log->receiver_name ?? 'the consignee')
                    .' at '.($log->trip?->destination ?? 'destination')),
                'at' => $log->delivered_at,
                'tone' => Tone::Success->value,
            ]);

        $incidents = $this->incidents->query()
            ->limit($limit)
            ->get()
            ->map(fn ($incident): array => [
                'id' => 'incident-'.$incident->id,
                'icon' => 'incident',
                'title' => $incident->reference.' · '.$incident->kind,
                'detail' => $incident->place,
                'at' => $incident->occurred_at,
                'tone' => Tone::Danger->value,
            ]);

        $dispatches = $this->trips->query()
            ->where('status', StatusValue::InTransit->value)
            ->limit($limit)
            ->get()
            ->map(fn ($trip): array => [
                'id' => 'trip-'.$trip->id,
                'icon' => 'map-pin',
                'title' => $trip->reference.' on the road',
                'detail' => $trip->origin.' to '.$trip->destination,
                'at' => $trip->scheduled_at,
                'tone' => Tone::Info->value,
            ]);

        return $deliveries->concat($incidents)->concat($dispatches)
            ->filter(fn (array $row): bool => $row['at'] instanceof Carbon)
            ->sortByDesc('at')
            ->take($limit)
            ->map(fn (array $row): array => [
                ...$row,
                'at' => $row['at']->format('Y-m-d\TH:i:s\Z'),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function receivables(int $days = 30): array
    {
        $counts = $this->invoices->countsByStatus(InvoiceDirection::Receivable);

        $from = now()->subDays($days - 1)->startOfDay();
        $to = now()->endOfDay();

        $ledger = $this->finance->periodRollup(
            Carbon::instance($from),
            Carbon::instance($to),
        )['totals'];

        $collection = $this->invoices->collectionBetween($from, $to);

        return [
            'pending_payment_cents' => $this->invoices->outstanding(InvoiceDirection::Receivable),
            'successful_payment_cents' => $this->invoices->collected(InvoiceDirection::Receivable),
            'overdue_cents' => $this->invoices->overdueTotal(InvoiceDirection::Receivable),
            'income_cents' => (int) $ledger['total_income_cents'],
            'expenses_cents' => (int) $ledger['total_expenses_cents'],
            'net_income_cents' => (int) $ledger['net_income_cents'],
            'pending_count' => ($counts[StatusValue::Pending->value] ?? 0)
                + ($counts[StatusValue::Partial->value] ?? 0)
                + ($counts[StatusValue::Overdue->value] ?? 0),
            'collection' => [
                ...$collection,
                'rate_pct' => $collection['billed_cents'] === 0
                    ? null
                    : (int) round($collection['collected_cents'] * 100 / $collection['billed_cents']),
            ],
            'paid_count' => $counts[StatusValue::Paid->value] ?? 0,
            'window_days' => $days,
            'currency' => 'PHP',
        ];
    }
}
