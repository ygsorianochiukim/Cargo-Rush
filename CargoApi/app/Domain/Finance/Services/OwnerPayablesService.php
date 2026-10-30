<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Payroll\Support\PayPeriod;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Trucker\Services\WalletService;
use App\Domain\Vehicle\Models\MaintenanceJob;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class OwnerPayablesService
{
    public const SETTLE_AT = '/payables/owners';

    public const UNPAID = 'unpaid';

    public const PROCESSING = 'processing';

    public const PAID = 'paid';

    public const OPEN = 'open';

    public const NOTHING = 'nothing';

    public const FUEL = 'fuel';

    public const ALLOWANCE = 'allowance';

    public const MAINTENANCE = 'maintenance';

    public const COST_KINDS = [self::FUEL, self::ALLOWANCE, self::MAINTENANCE];

    public function __construct(
        private readonly WalletService $wallet,
        private readonly Tenant $tenant,
    ) {}

    /** @return list<string> */
    public function ownerIds(): array
    {
        return array_map(strval(...), array_keys($this->fleetByOwner()->all()));
    }

    /** @return list<array<string, mixed>> */
    public function owed(): array
    {
        $calendar = $this->calendar();
        $closed = $calendar->justClosed();
        $lines = [];

        foreach ($this->fleetByOwner() as $trucks) {
            $owner = $trucks->first()?->ownerPartner;

            if ($owner === null) {
                continue;
            }

            $earliest = $this->earliestUnsettled($owner, $trucks);

            if ($earliest === null) {
                continue;
            }

            foreach ($calendar->between($earliest, $closed->end) as $period) {
                if ($period->end->gt($closed->end)) {
                    continue;
                }

                $cutoff = $this->cutoff($owner, $trucks, $period, $calendar);

                if (in_array($cutoff['state'], [self::PAID, self::NOTHING, self::OPEN], true)) {
                    continue;
                }

                $lines[] = $this->line($owner, $trucks, $cutoff);
            }
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    public function statement(Carbon $from, Carbon $to, ?string $truckerId = null): array
    {
        $calendar = $this->calendar();
        $periods = $calendar->between($from, $to);

        $owners = $this->fleetByOwner()
            ->when($truckerId !== null, static fn (Collection $fleet) => $fleet->only([$truckerId]))
            ->map(function (Collection $trucks) use ($periods, $calendar): ?array {
                $owner = $trucks->first()?->ownerPartner;

                if ($owner === null) {
                    return null;
                }

                $cutoffs = array_map(fn (PayPeriod $period): array => $this->cutoff($owner, $trucks, $period, $calendar), $periods);
                $unpaid = array_filter($cutoffs, static fn (array $cutoff): bool => $cutoff['state'] === self::UNPAID);
                $sum = static fn (string $key): int => (int) array_sum(array_column($cutoffs, $key));

                return [
                    'id' => $owner->getKey(),
                    'name' => $owner->name,
                    'business_name' => $owner->business_name,
                    'plates' => $trucks->pluck('plate')->values()->all(),
                    'trucks' => $trucks->map(static fn (Vehicle $vehicle): array => [
                        'id' => $vehicle->getKey(),
                        'plate' => $vehicle->plate,
                        'arrangement' => $vehicle->terms()->value,
                        'arrangement_label' => $vehicle->terms()->label(),
                        'fleet_keeps_bp' => $vehicle->shareRateBp(),
                        'owner_keeps_bp' => 10_000 - $vehicle->shareRateBp(),
                    ])->values()->all(),
                    'cutoffs' => array_reverse($cutoffs),
                    'totals' => [
                        'runs' => $sum('runs_count'),
                        'gross_cents' => $sum('gross_cents'),
                        'commission_cents' => $sum('commission_cents'),
                        'share_cents' => $sum('share_cents'),
                        'fuel_cents' => $sum('fuel_cents'),
                        'allowance_cents' => $sum('allowance_cents'),
                        'maintenance_cents' => $sum('maintenance_cents'),
                        'costs_cents' => $sum('costs_cents'),
                        'net_cents' => $sum('net_cents'),
                        'unpaid_cents' => array_sum(array_map(static fn (array $cutoff): int => max(0, $cutoff['net_cents']), $unpaid)),
                    ],
                ];
            })
            ->filter()
            ->sortBy('name')
            ->values()
            ->all();

        return [
            'from' => $periods === [] ? $from->toDateString() : $periods[0]->start->toDateString(),
            'to' => $periods === [] ? $to->toDateString() : end($periods)->end->toDateString(),
            'calendar' => $calendar->describe(),
            'owners' => $owners,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function history(Trucker $owner, int $limit = 100): array
    {
        $settlements = WalletEntry::query()
            ->where('trucker_id', $owner->getKey())
            ->whereIn('kind', [
                WalletEntryKind::Payout->value,
                WalletEntryKind::Remittance->value,
                WalletEntryKind::Adjustment->value,
            ])
            ->withCount(['settles as runs_count' => static fn ($query) => $query
                ->where('kind', WalletEntryKind::Earning->value)])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $settlements
            ->map(static function (WalletEntry $entry): array {
                $period = $entry->coversCutoff()
                    ? PayPeriod::formatRange($entry->period_start, $entry->period_end)
                    : null;

                return [
                    'id' => $entry->getKey(),
                    'kind' => $entry->kind->value,
                    'kind_label' => $entry->kind->label(),
                    'description' => $entry->describe(),
                    'amount_cents' => (int) $entry->amount_cents,
                    'status' => $entry->status->value,
                    'state' => $entry->kind === WalletEntryKind::Adjustment
                        ? ($entry->amount_cents < 0 ? 'charged' : 'credited')
                        : ($entry->isInFlight() ? self::PROCESSING : self::PAID),
                    'method' => $entry->method,
                    'reference' => $entry->reference,
                    'note' => $entry->note,
                    'occurred_on' => $entry->occurred_on?->toDateString(),
                    'period_start' => $entry->period_start?->toDateString(),
                    'period_end' => $entry->period_end?->toDateString(),
                    'period_label' => $period,
                    'runs_count' => (int) $entry->runs_count,
                ];
            })
            ->values()
            ->all();
    }

    public function settle(
        Trucker $owner,
        Carbon $start,
        Carbon $end,
        ?string $reference = null,
        ?string $note = null,
        ?int $recordedBy = null,
        ?CarbonInterface $occurredOn = null,
        string $method = WalletService::DEFAULT_METHOD,
        bool $cleared = false,
    ): WalletEntry {
        $calendar = $this->calendar();
        $period = $calendar->matching($start, $end);

        abort_if($period === null, 422, $calendar->explainFor($start));
        abort_if($period->end->gte(now()->startOfDay()), 422, "The {$period->label()} cutoff has not closed yet.");

        $trucks = $this->fleetByOwner()->get($owner->getKey());

        abort_if($trucks === null, 422, "{$owner->name} does not own a rented or sub-contracted truck.");

        $cutoff = $this->cutoff($owner, $trucks, $period, $calendar);

        abort_if(
            in_array($cutoff['state'], [self::PAID, self::PROCESSING], true),
            422,
            "The {$period->label()} cutoff has already been settled for {$owner->name}.",
        );

        $entryIds = array_values(array_map(
            static fn (array $run): string => $run['id'],
            array_filter($cutoff['runs'], static fn (array $run): bool => $run['state'] === self::UNPAID),
        ));

        return $this->wallet->payOutCutoff(
            trucker: $owner,
            entryIds: $entryIds,
            costsCents: $cutoff['costs_cents'],
            costsNote: $this->costsNote($cutoff),
            periodStart: $period->start,
            periodEnd: $period->end,
            periodLabel: $period->label(),
            reference: $reference,
            note: $note,
            recordedBy: $recordedBy,
            occurredOn: $occurredOn,
            method: $method,
            cleared: $cleared,
        );
    }

    /**
     * @param  Collection<int, Vehicle>  $trucks
     * @return array<string, mixed>
     */
    private function cutoff(Trucker $owner, Collection $trucks, PayPeriod $period, PayrollCalendar $calendar): array
    {
        $start = $period->start->toDateString();
        $end = $period->end->toDateString();
        $vehicleIds = $trucks->modelKeys();
        $plates = $trucks->pluck('plate', 'id');

        $marker = WalletEntry::query()
            ->forCutoff($owner->getKey(), $start, $end)
            ->whereIn('kind', [WalletEntryKind::Payout->value, WalletEntryKind::Adjustment->value])
            ->get();

        $payout = $marker->first(static fn (WalletEntry $entry): bool => $entry->kind === WalletEntryKind::Payout);
        $deduction = $marker->first(static fn (WalletEntry $entry): bool => $entry->kind === WalletEntryKind::Adjustment);
        $markerIds = $marker->modelKeys();

        $runs = WalletEntry::query()
            ->where('trucker_id', $owner->getKey())
            ->where('kind', WalletEntryKind::Earning->value)
            ->whereNotNull('trip_id')
            ->whereHas('trip', static fn ($trip) => $trip->withTrashed()->whereIn('vehicle_id', $vehicleIds))
            ->whereDate('occurred_on', '>=', $start)
            ->whereDate('occurred_on', '<=', $end)
            ->with([
                'trip' => static fn ($trip) => $trip->withTrashed()
                    ->select(['id', 'reference', 'origin', 'destination', 'vehicle_id', 'price_cents', 'customer_id'])
                    ->with('customer:id,name,vat_treatment'),
                'settlement:id,kind,status,reference,occurred_on',
            ])
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->get()
            ->map(static function (WalletEntry $entry) use ($plates, $markerIds): array {
                $onThisCutoff = $entry->settled_by !== null && in_array($entry->settled_by, $markerIds, true);
                $price = (int) ($entry->trip?->price_cents ?? 0);
                $base = (int) $entry->gross_cents;
                $share = (int) $entry->amount_cents;

                return [
                    'id' => $entry->getKey(),
                    'trip_id' => $entry->trip_id,
                    'reference' => $entry->trip?->reference,
                    'on' => $entry->occurred_on?->toDateString(),
                    'origin' => $entry->trip?->origin,
                    'destination' => $entry->trip?->destination,
                    'customer' => $entry->trip?->customer?->name,
                    'plate' => $plates[$entry->trip?->vehicle_id] ?? null,
                    'price_cents' => $price,
                    'vat_treatment_label' => $entry->trip?->customer?->vatTreatment()->label(),
                    'vat_in_price_cents' => max(0, $price - $base),
                    'gross_cents' => $base,
                    'fleet_keeps_bp' => (int) $entry->rate_bp,
                    'commission_cents' => $base - $share,
                    'share_cents' => $share,
                    'state' => $entry->paymentState() ?? self::UNPAID,
                    'counted' => $entry->settled_by === null || $onThisCutoff,
                    'paid_elsewhere' => $entry->settled_by !== null && ! $onThisCutoff
                        ? $entry->settlement?->reference ?? $entry->settlement?->occurred_on?->toDateString()
                        : null,
                ];
            })
            ->values();

        $trucksByFinanceId = Truck::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->get(['id', 'vehicle_id'])
            ->mapWithKeys(static fn (Truck $truck): array => [$truck->getKey() => $plates[$truck->vehicle_id] ?? $truck->plate]);

        $ledger = $trucksByFinanceId->isEmpty()
            ? collect()
            : LedgerEntry::query()
                ->whereIn('truck_id', $trucksByFinanceId->keys()->all())
                ->whereDate('date', '>=', $start)
                ->whereDate('date', '<=', $end)
                ->where(static fn ($query) => $query
                    ->where('fuel_cents', '>', 0)
                    ->orWhere('allowance_cents', '>', 0)
                    ->orWhere('maintenance_cents', '>', 0))
                ->get(['id', 'truck_id', 'date', 'fuel_cents', 'allowance_cents', 'maintenance_cents']);

        $onSheet = [
            self::FUEL => (int) $ledger->sum('fuel_cents'),
            self::ALLOWANCE => (int) $ledger->sum('allowance_cents'),
            self::MAINTENANCE => (int) $ledger->sum('maintenance_cents'),
        ];

        $costs = $trucksByFinanceId->isEmpty()
            ? collect()
            : $this->costSources($trucks, $trucksByFinanceId, $ledger, $start, $end);

        $counted = $runs->where('counted', true);
        $gross = (int) $counted->sum('gross_cents');
        $share = (int) $counted->sum('share_cents');
        $paidElsewhere = (int) $runs->where('counted', false)->sum('share_cents');
        $costsCents = $deduction === null ? array_sum($onSheet) : abs((int) $deduction->amount_cents);
        $net = $share - $costsCents;

        $state = match (true) {
            $payout !== null => $payout->isInFlight() ? self::PROCESSING : self::PAID,
            $deduction !== null => self::PAID,
            $period->end->gte(now()->startOfDay()) => self::OPEN,
            $share === 0 && $costsCents === 0 => self::NOTHING,
            default => self::UNPAID,
        };

        $settlement = $payout ?? $deduction;

        return [
            'start' => $start,
            'end' => $end,
            'label' => $period->label(),
            'short' => $period->short(),
            'due_on' => $calendar->releaseFor($period->end)->toDateString(),
            'state' => $state,
            'runs' => $runs->all(),
            'runs_count' => $counted->count(),
            'costs' => $costs->all(),
            'gross_cents' => $gross,
            'commission_cents' => $gross - $share,
            'share_cents' => $share,
            'paid_elsewhere_cents' => $paidElsewhere,
            'fuel_cents' => $onSheet[self::FUEL],
            'allowance_cents' => $onSheet[self::ALLOWANCE],
            'maintenance_cents' => $onSheet[self::MAINTENANCE],
            'costs_cents' => $costsCents,
            'net_cents' => $net,
            'settlement' => $settlement === null ? null : [
                'id' => $settlement->getKey(),
                'kind' => $settlement->kind->value,
                'amount_cents' => abs((int) $settlement->amount_cents),
                'reference' => $settlement->reference,
                'method' => $settlement->method,
                'occurred_on' => $settlement->occurred_on?->toDateString(),
                'status' => $settlement->status->value,
            ],
        ];
    }

    /**
     * @param  Collection<int, Vehicle>  $trucks
     * @param  Collection<string, string|null>  $trucksByFinanceId
     * @param  Collection<int, LedgerEntry>  $ledger
     * @return Collection<int, array<string, mixed>>
     */
    private function costSources(Collection $trucks, Collection $trucksByFinanceId, Collection $ledger, string $start, string $end): Collection
    {
        $truckIds = $trucksByFinanceId->keys()->all();
        $plates = $trucks->pluck('plate', 'id');

        $fills = FuelRecord::query()
            ->whereIn('posted_truck_id', $truckIds)
            ->where('posted_cents', '>', 0)
            ->whereDate('posted_on', '>=', $start)
            ->whereDate('posted_on', '<=', $end)
            ->with(['driver:id,name', 'paymentSource:id,name,last_four'])
            ->orderBy('posted_on')
            ->get()
            ->map(static fn (FuelRecord $fill): array => [
                'kind' => self::FUEL,
                'source' => 'fill',
                'on' => $fill->posted_on?->toDateString(),
                'plate' => $trucksByFinanceId[$fill->posted_truck_id] ?? null,
                'label' => $fill->receipt_no === null ? 'Fuel fill' : "Fill · {$fill->receipt_no}",
                'detail' => $fill->driver?->name,
                'litres' => $fill->litres === null ? null : (float) $fill->litres,
                'paid_with' => $fill->paymentSource?->label(),
                'cents' => (int) $fill->posted_cents,
            ]);

        $logs = DeliveryLog::query()
            ->whereIn('posted_truck_id', $truckIds)
            ->where(static fn ($query) => $query
                ->where('posted_fuel_cents', '>', 0)
                ->orWhere('posted_driver_allowance_cents', '>', 0)
                ->orWhere('posted_helper_allowance_cents', '>', 0))
            ->whereDate('posted_on', '>=', $start)
            ->whereDate('posted_on', '<=', $end)
            ->with([
                'trip' => static fn ($trip) => $trip->withTrashed()->select(['id', 'reference', 'origin', 'destination']),
                'paymentSource:id,name,last_four',
            ])
            ->orderBy('posted_on')
            ->get();

        $onRuns = $logs
            ->filter(static fn (DeliveryLog $log): bool => (int) $log->posted_fuel_cents > 0)
            ->map(static fn (DeliveryLog $log): array => [
                'kind' => self::FUEL,
                'source' => 'run',
                'on' => $log->posted_on?->toDateString(),
                'plate' => $trucksByFinanceId[$log->posted_truck_id] ?? null,
                'label' => $log->trip?->reference === null ? 'Fuel on a run' : "Run {$log->trip->reference}",
                'detail' => $log->trip === null ? null : "{$log->trip->origin} → {$log->trip->destination}",
                'litres' => $log->fuel_litres === null ? null : (float) $log->fuel_litres,
                'paid_with' => $log->paymentSource?->label(),
                'cents' => (int) $log->posted_fuel_cents,
            ]);

        $allowances = $logs
            ->flatMap(static function (DeliveryLog $log) use ($trucksByFinanceId): array {
                $run = $log->trip?->reference === null ? 'a run' : "run {$log->trip->reference}";
                $route = $log->trip === null ? null : "{$log->trip->origin} → {$log->trip->destination}";
                $rows = [];

                foreach ([
                    ['Driver allowance', (int) $log->posted_driver_allowance_cents],
                    ['Helper allowance', (int) $log->posted_helper_allowance_cents],
                ] as [$who, $cents]) {
                    if ($cents > 0) {
                        $rows[] = [
                            'kind' => self::ALLOWANCE,
                            'source' => 'run',
                            'on' => $log->posted_on?->toDateString(),
                            'plate' => $trucksByFinanceId[$log->posted_truck_id] ?? null,
                            'label' => "{$who} · {$run}",
                            'detail' => $route,
                            'litres' => null,
                            'paid_with' => null,
                            'cents' => $cents,
                        ];
                    }
                }

                return $rows;
            });

        $jobs = MaintenanceJob::query()
            ->whereIn('vehicle_id', $trucks->modelKeys())
            ->where('posted_cents', '>', 0)
            ->whereDate('completed_on', '>=', $start)
            ->whereDate('completed_on', '<=', $end)
            ->with('supplier:id,name')
            ->orderBy('completed_on')
            ->get()
            ->map(static fn (MaintenanceJob $job): array => [
                'kind' => self::MAINTENANCE,
                'source' => 'job',
                'on' => $job->completed_on?->toDateString(),
                'plate' => $plates[$job->vehicle_id] ?? null,
                'label' => $job->kind,
                'detail' => trim(implode(' · ', array_filter([$job->supplier?->name, $job->reference]))) ?: null,
                'litres' => null,
                'paid_with' => null,
                'cents' => (int) $job->posted_cents,
            ]);

        $sources = $fills->concat($onRuns)->concat($allowances)->concat($jobs)->sortBy('on')->values();

        $labels = [
            self::FUEL => ['Fuel entered on the truck sheet', 'Typed straight into the ledger, not from a fill or a run'],
            self::ALLOWANCE => ['Allowance entered on the truck sheet', 'Typed straight into the ledger, not from a run'],
            self::MAINTENANCE => ['Maintenance entered on the truck sheet', 'Typed straight into the ledger, not from a service job'],
        ];

        $remainders = collect();

        foreach (self::COST_KINDS as $kind) {
            $explained = $sources
                ->where('kind', $kind)
                ->groupBy('plate')
                ->map(static fn (Collection $rows): int => (int) $rows->sum('cents'));

            $ledger
                ->groupBy(static fn (LedgerEntry $row): string => (string) ($trucksByFinanceId[$row->truck_id] ?? ''))
                ->map(static fn (Collection $rows): int => (int) $rows->sum("{$kind}_cents"))
                ->map(static fn (int $total, string $plate): int => $total - (int) ($explained[$plate] ?? 0))
                ->filter(static fn (int $left): bool => $left > 0)
                ->each(static function (int $left, string $plate) use (&$remainders, $kind, $labels): void {
                    $remainders->push([
                        'kind' => $kind,
                        'source' => 'ledger',
                        'on' => null,
                        'plate' => $plate === '' ? null : $plate,
                        'label' => $labels[$kind][0],
                        'detail' => $labels[$kind][1],
                        'litres' => null,
                        'paid_with' => null,
                        'cents' => $left,
                    ]);
                });
        }

        return $sources->concat($remainders)->values();
    }

    /** @param array<string, mixed> $cutoff */
    private function costsNote(array $cutoff): string
    {
        $parts = [];

        foreach ([self::FUEL => 'fuel', self::ALLOWANCE => 'allowances', self::MAINTENANCE => 'maintenance'] as $kind => $word) {
            if ($cutoff["{$kind}_cents"] > 0) {
                $parts[] = "{$word} {$this->peso($cutoff["{$kind}_cents"])}";
            }
        }

        return ucfirst(implode(', ', $parts))." on {$cutoff['label']}";
    }

    /**
     * @param  Collection<int, Vehicle>  $trucks
     * @param  array<string, mixed>  $cutoff
     * @return array<string, mixed>
     */
    private function line(Trucker $owner, Collection $trucks, array $cutoff): array
    {
        $processing = $cutoff['state'] === self::PROCESSING;
        $sent = $processing ? (int) $cutoff['settlement']['amount_cents'] : 0;
        $runs = (int) $cutoff['runs_count'];

        $detail = sprintf(
            '%s · %s · %d %s %s',
            $trucks->pluck('plate')->implode(', '),
            $cutoff['label'],
            $runs,
            $runs === 1 ? 'run' : 'runs',
            $this->peso($cutoff['share_cents']),
        );

        foreach ([self::FUEL => 'fuel', self::ALLOWANCE => 'allowance', self::MAINTENANCE => 'maintenance'] as $kind => $word) {
            if ($cutoff["{$kind}_cents"] > 0) {
                $detail .= " − {$word} {$this->peso($cutoff["{$kind}_cents"])}";
            }
        }

        if ($cutoff['net_cents'] < 0) {
            $detail .= ' · costs exceed the share by '.$this->peso(-$cutoff['net_cents']);
        }

        return [
            'id' => "{$owner->getKey()}:{$cutoff['start']}:{$cutoff['end']}",
            'trucker_id' => $owner->getKey(),
            'period_start' => $cutoff['start'],
            'period_end' => $cutoff['end'],
            'period_label' => $cutoff['label'],
            'name' => $owner->name,
            'detail' => $detail,
            'amount_cents' => $processing ? $sent : max(0, (int) $cutoff['net_cents']),
            'in_flight_cents' => $sent,
            'due_on' => $cutoff['due_on'],
            'settle_at' => self::SETTLE_AT,
        ];
    }

    /** @return Collection<string, Collection<int, Vehicle>> */
    private function fleetByOwner(): Collection
    {
        return Vehicle::query()
            ->withTrashed()
            ->with('ownerPartner')
            ->whereNotNull('owner_trucker_id')
            ->orderBy('plate')
            ->get()
            ->filter(static fn (Vehicle $vehicle): bool => $vehicle->sharesRevenue())
            ->groupBy('owner_trucker_id')
            ->toBase();
    }

    /** @param Collection<int, Vehicle> $trucks */
    private function earliestUnsettled(Trucker $owner, Collection $trucks): ?Carbon
    {
        $vehicleIds = $trucks->modelKeys();

        $first = WalletEntry::query()
            ->where('trucker_id', $owner->getKey())
            ->where('kind', WalletEntryKind::Earning->value)
            ->landed()
            ->whereNull('settled_by')
            ->whereNotNull('trip_id')
            ->whereHas('trip', static fn ($trip) => $trip->withTrashed()->whereIn('vehicle_id', $vehicleIds))
            ->min('occurred_on');

        $onItsWay = WalletEntry::query()
            ->where('trucker_id', $owner->getKey())
            ->where('kind', WalletEntryKind::Payout->value)
            ->inFlight()
            ->whereNotNull('period_start')
            ->min('period_start');

        $dates = array_filter([$first, $onItsWay]);

        return $dates === [] ? null : Carbon::parse((string) min($dates));
    }

    private function calendar(): PayrollCalendar
    {
        return PayrollCalendar::for($this->tenant->company());
    }

    private function peso(int $cents): string
    {
        return '₱'.number_format($cents / 100, 2);
    }
}
