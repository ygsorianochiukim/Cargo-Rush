<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Services;

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

/**
 * The consolidated view — DESIGN.md section 5.1, Dashboard.
 *
 * This module owns no table. It composes the other modules' repositories, so
 * a KPI here is by construction the same number the module page prints.
 */
class DashboardService
{
    /** The labels the fleet doughnut prints against each status. */
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
        // Composed, not reimplemented: the two money formulas live in
        // FinanceService and a KPI here must be the same arithmetic the
        // Profitability page prints.
        private readonly FinanceService $finance,
    ) {}

    /**
     * The four tiles. `delta` is null when there is no prior period to
     * compare against — the client renders no arrow rather than a fake 0%.
     *
     * @return array<int, array<string, mixed>>
     */
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

    /**
     * Where the fleet is, by status.
     *
     * Every bucket is emitted even at zero, so the doughnut keeps the same
     * four segments and the same colours from one refresh to the next.
     *
     * @return array<int, array<string, mixed>>
     */
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

    /**
     * Deliveries closed out per day for the last week.
     *
     * Days with none are filled in as zero: a gap in a bar chart reads as
     * "no data", and a quiet Sunday is data.
     *
     * @return array<int, array<string, mixed>>
     */
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

    /**
     * The activity feed — the most recent things that actually happened,
     * merged from three modules and cut to the newest few.
     *
     * @return array<int, array<string, mixed>>
     */
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

    /**
     * Money owed against money collected — the two figures the Dashboard was
     * missing entirely.
     *
     * Until deliveries raised their own receivables there was nothing honest
     * to put here: the only record of what a haul earned was a figure somebody
     * had typed into a ledger row, and the only record of what had been
     * *billed* was whatever invoices somebody had remembered to raise. Neither
     * could be added up and called the business's position.
     *
     * Three numbers, and they answer three different questions:
     *
     *  - **pending_payment_cents** — invoiced and not yet settled, overdue
     *    included. What is out there.
     *  - **successful_payment_cents** — settled. What actually arrived.
     *  - **income_cents** — what the fleet earned on the road over the window,
     *    from the ledger. Deliberately not the same as either: a run delivered
     *    on the last day of the month is income now and cash in thirty days,
     *    and a dashboard that conflated the two would call a good month a
     *    cash-flow problem, or the reverse.
     *
     * `overdue_cents` is carried separately rather than deducted, because it is
     * a subset of what is pending and not a fourth bucket — it is the part of
     * it that needs chasing.
     *
     * @return array<string, mixed>
     */
    public function receivables(int $days = 30): array
    {
        $counts = $this->invoices->countsByStatus(InvoiceDirection::Receivable);

        $from = now()->subDays($days - 1)->startOfDay();
        $to = now()->endOfDay();

        $ledger = $this->finance->periodTotals(
            $this->finance->pnlByTruck(Carbon::instance($from), Carbon::instance($to)),
        );

        return [
            'pending_payment_cents' => $this->invoices->outstanding(InvoiceDirection::Receivable),
            'successful_payment_cents' => $this->invoices->collected(InvoiceDirection::Receivable),
            'overdue_cents' => $this->invoices->overdueTotal(InvoiceDirection::Receivable),
            'income_cents' => (int) $ledger['trip_income_cents'],
            'expenses_cents' => (int) $ledger['total_expenses_cents'],
            'net_income_cents' => (int) $ledger['net_income_cents'],
            'pending_count' => ($counts[StatusValue::Pending->value] ?? 0)
                + ($counts[StatusValue::Overdue->value] ?? 0),
            'paid_count' => $counts[StatusValue::Paid->value] ?? 0,
            'window_days' => $days,
            'currency' => 'PHP',
        ];
    }
}
