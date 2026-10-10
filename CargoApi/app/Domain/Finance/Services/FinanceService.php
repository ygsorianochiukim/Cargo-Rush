<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Billing\Repositories\InvoiceRepository;
use App\Domain\Finance\DTO\LedgerEntryData;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Finance\Repositories\ExpenseRepository;
use App\Domain\Finance\Repositories\LedgerRepository;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Fuel\Repositories\FuelRepository;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Services\WalletService;
use App\Domain\Vehicle\Models\MaintenanceJob;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceService
{
    public function __construct(
        private readonly LedgerRepository $ledger,
        private readonly ExpenseRepository $expenses,
        private readonly InvoiceRepository $invoices,
        private readonly PayablesService $payables,
        private readonly WalletService $wallet,
        private readonly ReceivablesService $receivables,
        private readonly FuelRepository $fuel,
        private readonly PayrollCostService $payroll,
    ) {}

    public function quarters(int $year): array
    {
        return [
            ['key' => 'q1', 'label' => '1st Quarter', 'from' => "$year-01-01", 'to' => "$year-03-31"],
            ['key' => 'q2', 'label' => '2nd Quarter', 'from' => "$year-04-01", 'to' => "$year-06-30"],
            ['key' => 'q3', 'label' => '3rd Quarter', 'from' => "$year-07-01", 'to' => "$year-09-30"],
            ['key' => 'q4', 'label' => '4th Quarter', 'from' => "$year-10-01", 'to' => "$year-12-31"],
        ];
    }

    public function tenDayRange(Carbon $from): array
    {
        return ['from' => $from->toDateString(), 'to' => $from->copy()->addDays(9)->toDateString()];
    }

    /** @return array<int, array<string, mixed>> */
    public function pnlByTruck(Carbon $from, Carbon $to): array
    {
        $trucks = $this->ledger->trucks();
        $entries = $this->ledger->entriesBetween($from, $to)->groupBy('truck_id');
        $lines = $this->expenses->between($from, $to)->groupBy('truck_id');
        $fills = $this->fuelByTruck($from, $to, $trucks);
        $posted = $this->fuel->postedBetween($from, $to)->groupBy('posted_truck_id');

        $rows = $trucks->map(function (Truck $truck) use ($entries, $lines, $fills, $posted): array {
            /** @var Collection<int, LedgerEntry> $mine */
            $mine = $entries->get($truck->id, collect());

            $income = (int) $mine->sum('trip_income_cents');
            $columns = (int) $mine->sum(fn (LedgerEntry $e): int => $e->totalExpensesCents());
            $other = (int) $lines->get($truck->id, collect())->sum('amount_cents');
            $unposted = (int) $fills->get($truck->id, collect())->sum('amount_cents');
            $logged = $unposted + (int) $posted->get($truck->id, collect())->sum('posted_cents');
            $expenses = $columns + $other + $unposted;

            return [
                'truck' => [
                    'id' => $truck->id,
                    'label' => $truck->label,
                    'plate' => $truck->plate,
                ],
                'trip_income_cents' => $income,
                'fuel_cents' => (int) $mine->sum('fuel_cents') + $unposted,
                'fuel_log_cents' => $logged,
                'driver_salary_cents' => (int) $mine->sum('driver_salary_cents'),
                'helper_salary_cents' => (int) $mine->sum('helper_salary_cents'),
                'maintenance_cents' => (int) $mine->sum('maintenance_cents'),
                'allowance_cents' => (int) $mine->sum('allowance_cents'),
                'owner_share_cents' => (int) $mine->sum('owner_share_cents'),
                'other_expenses_cents' => $other,
                'total_expenses_cents' => $expenses,
                'net_income_cents' => $income - $expenses,
                'net_share' => 0.0,
                'entry_count' => $mine->count(),
            ];
        })->all();

        $totalNet = array_sum(array_column($rows, 'net_income_cents'));
        foreach ($rows as $i => $row) {
            $rows[$i]['net_share'] = $totalNet === 0 ? 0.0 : $row['net_income_cents'] / $totalNet;
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function periodTotals(
        array $rows,
        int $overheadCents = 0,
        int $supplierBillsCents = 0,
        int $payablesCents = 0,
        int $truckerPayoutsCents = 0,
        int $receivablesCents = 0,
        int $truckerCommissionCents = 0,
        int $vatCollectedCents = 0,
        int $heldForPartnersCents = 0,
        int $payrollCents = 0,
        int $otherIncomeCents = 0,
        int $payablesAlreadyCostedCents = 0,
    ): array {
        $sum = static fn (string $key): int => (int) array_sum(array_column($rows, $key));

        $tripIncome = $sum('trip_income_cents');
        $income = $tripIncome + $truckerCommissionCents + $otherIncomeCents;
        $expenses = $sum('total_expenses_cents')
            + $overheadCents
            + $supplierBillsCents
            + $payrollCents;

        return [
            'trip_income_cents' => $tripIncome,
            'trucker_commission_cents' => $truckerCommissionCents,
            'other_income_cents' => $otherIncomeCents,
            'total_income_cents' => $income,
            'vat_collected_cents' => $vatCollectedCents,
            'fuel_cents' => $sum('fuel_cents'),
            'fuel_log_cents' => $sum('fuel_log_cents'),
            'driver_salary_cents' => $sum('driver_salary_cents'),
            'helper_salary_cents' => $sum('helper_salary_cents'),
            'maintenance_cents' => $sum('maintenance_cents'),
            'allowance_cents' => $sum('allowance_cents'),
            'owner_share_cents' => $sum('owner_share_cents'),
            'other_expenses_cents' => $sum('other_expenses_cents'),
            'overhead_cents' => $overheadCents,
            'supplier_bills_cents' => $supplierBillsCents,
            'payroll_cents' => $payrollCents,
            'trucker_payouts_cents' => $truckerPayoutsCents,
            'total_expenses_cents' => $expenses,
            'net_income_cents' => $income - $expenses,

            'payables_cents' => $payablesCents,
            'held_for_partners_cents' => $heldForPartnersCents,
            'payables_already_costed_cents' => $payablesAlreadyCostedCents,
            'actual_income_cents' => $income - $expenses
                - ($payablesCents - $heldForPartnersCents - $payablesAlreadyCostedCents),
            'receivables_cents' => $receivablesCents,
            'margin' => $income === 0 ? null : ($income - $expenses) / $income,
        ];
    }

    public function overheadCents(Carbon $from, Carbon $to): int
    {
        $lines = (int) $this->expenses->between($from, $to)
            ->whereNull('truck_id')
            ->sum('amount_cents');

        $fills = (int) $this->fuelByTruck($from, $to, $this->ledger->trucks())
            ->get('', collect())
            ->sum('amount_cents');

        return $lines + $fills;
    }

    /**
     * @param  Collection<int, Truck>  $trucks
     * @return Collection<string, Collection<int, FuelRecord>>
     */
    private function fuelByTruck(Carbon $from, Carbon $to, Collection $trucks): Collection
    {
        $truckOf = $trucks->whereNotNull('vehicle_id')->pluck('id', 'vehicle_id');

        return $this->fuel->unpostedBetween($from, $to)
            ->groupBy(static fn (FuelRecord $fill): string => (string) ($truckOf[$fill->vehicle_id] ?? ''));
    }

    public function supplierBillsPaidCents(Carbon $from, Carbon $to): int
    {
        return (int) $this->supplierBillSettlements($from, $to)->sum('amount_cents');
    }

    /** @return Collection<int, PaymentAllocation> */
    public function supplierBillSettlements(Carbon $from, Carbon $to): Collection
    {
        $serviced = MaintenanceJob::query()
            ->whereNotNull('invoice_id')
            ->where('posted_cents', '>', 0)
            ->pluck('invoice_id')
            ->unique()
            ->all();

        return $this->invoices->settlementsBetween(InvoiceDirection::Payable, $from, $to)
            ->reject(static fn (PaymentAllocation $allocation): bool => in_array($allocation->invoice_id, $serviced, true))
            ->values();
    }

    /** @return Collection<int, Invoice> */
    public function manualInvoicesBetween(Carbon $from, Carbon $to): Collection
    {
        return Invoice::query()
            ->with('customer:id,name')
            ->where('direction', InvoiceDirection::Receivable->value)
            ->whereNull('trip_id')
            ->where('status', '!=', StatusValue::Cancelled->value)
            ->whereDate('issued_at', '>=', $from->toDateString())
            ->whereDate('issued_at', '<=', $to->toDateString())
            ->get();
    }

    public static function invoiceNetCents(Invoice $invoice): int
    {
        return $invoice->net_amount_cents !== null
            ? (int) $invoice->net_amount_cents
            : (int) $invoice->amount_cents - (int) $invoice->vat_cents;
    }

    public function otherIncomeCents(Carbon $from, Carbon $to): int
    {
        return (int) $this->manualInvoicesBetween($from, $to)
            ->sum(static fn (Invoice $invoice): int => self::invoiceNetCents($invoice));
    }

    /** @return array{trucks: array<int, array<string, mixed>>, totals: array<string, mixed>} */
    public function periodRollup(Carbon $from, Carbon $to): array
    {
        $rows = $this->pnlByTruck($from, $to);

        return [
            'trucks' => $rows,
            'totals' => $this->periodTotals(
                $rows,
                $this->overheadCents($from, $to),
                $this->supplierBillsPaidCents($from, $to),
                $this->payables->outstandingAsOf($to),
                $this->wallet->paidOutBetween($from, $to),
                $this->receivables->outstandingAsOf($to),
                $this->wallet->commissionEarnedBetween($from, $to),
                $this->invoices->vatRaisedBetween($from, $to),
                $this->wallet->heldForPartnersAsOf($to),
                $this->payroll->costBetween($from, $to),
                $this->otherIncomeCents($from, $to),
                $this->payables->alreadyCostedAsOf($to),
            ),
        ];
    }

    /**
     * @param  'daily'|'weekly'|'monthly'  $granularity
     * @return array<string, mixed>
     */
    public function sales(string $granularity, Carbon $from, Carbon $to): array
    {
        $entries = $this->ledger->entriesBetween($from, $to);
        $lines = $this->expenses->between($from, $to);
        $fills = $this->fuel->unpostedBetween($from, $to);
        $bills = $this->supplierBillSettlements($from, $to);
        $commissions = $this->wallet->commissionsEarnedBetween($from, $to);
        $payroll = $this->payroll->paidBetween($from, $to);
        $manual = $this->manualInvoicesBetween($from, $to);

        $buckets = [];

        foreach ($entries as $entry) {
            $key = $this->bucketKey($granularity, $entry->date);

            $buckets[$key] ??= $this->emptyBucket($granularity, $entry->date);
            $buckets[$key]['sales_cents'] += $entry->trip_income_cents;
            $buckets[$key]['expenses_cents'] += $entry->totalExpensesCents();
            $buckets[$key]['entry_count']++;
        }

        foreach ($lines as $line) {
            $key = $this->bucketKey($granularity, $line->date);

            $buckets[$key] ??= $this->emptyBucket($granularity, $line->date);
            $buckets[$key]['expenses_cents'] += $line->amount_cents;
        }

        foreach ($fills as $fill) {
            $key = $this->bucketKey($granularity, $fill->logged_at);

            $buckets[$key] ??= $this->emptyBucket($granularity, $fill->logged_at);
            $buckets[$key]['expenses_cents'] += $fill->amount_cents;
        }

        foreach ($bills as $bill) {
            $paidOn = $bill->payment?->paid_on;

            $key = $this->bucketKey($granularity, $paidOn);

            $buckets[$key] ??= $this->emptyBucket($granularity, $paidOn);
            $buckets[$key]['expenses_cents'] += $bill->amount_cents;
        }

        foreach ($commissions as $entry) {
            $key = $this->bucketKey($granularity, $entry->occurred_on);

            $buckets[$key] ??= $this->emptyBucket($granularity, $entry->occurred_on);
            $buckets[$key]['sales_cents'] += (int) ($entry->trip?->commission_cents ?? 0);
        }

        foreach ($payroll as $run) {
            $paidOn = Carbon::parse($run['date']);
            $key = $this->bucketKey($granularity, $paidOn);

            $buckets[$key] ??= $this->emptyBucket($granularity, $paidOn);
            $buckets[$key]['expenses_cents'] += $run['counted_cents'];
        }

        foreach ($manual as $invoice) {
            $key = $this->bucketKey($granularity, $invoice->issued_at);

            $buckets[$key] ??= $this->emptyBucket($granularity, $invoice->issued_at);
            $buckets[$key]['sales_cents'] += self::invoiceNetCents($invoice);
        }

        ksort($buckets);

        $series = array_values(array_map(static function (array $bucket): array {
            $bucket['net_cents'] = $bucket['sales_cents'] - $bucket['expenses_cents'];

            return $bucket;
        }, $buckets));

        $sales = (int) array_sum(array_column($series, 'sales_cents'));
        $expenses = (int) array_sum(array_column($series, 'expenses_cents'));

        return [
            'granularity' => $granularity,
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'series' => $series,
            'totals' => [
                'sales_cents' => $sales,
                'expenses_cents' => $expenses,
                'net_cents' => $sales - $expenses,
                'margin' => $sales === 0 ? null : ($sales - $expenses) / $sales,
                'average_cents' => $series === [] ? 0 : (int) round($sales / max(1, count(array_filter(
                    $series,
                    static fn (array $bucket): bool => $bucket['sales_cents'] !== 0,
                )))),
                'best' => $this->bestBucket($series),
            ],
            'currency' => 'PHP',
        ];
    }

    private function bucketKey(string $granularity, ?CarbonInterface $date): string
    {
        $date = $date instanceof CarbonInterface ? $date : Carbon::now();

        return match ($granularity) {
            'monthly' => $date->format('Y-m'),
            'weekly' => $date->copy()->startOfWeek()->format('o-\WW'),
            default => $date->toDateString(),
        };
    }

    /** @return array<string, mixed> */
    private function emptyBucket(string $granularity, ?CarbonInterface $date): array
    {
        $date = $date instanceof CarbonInterface ? $date : Carbon::now();

        [$start, $end, $label] = match ($granularity) {
            'monthly' => [
                $date->copy()->startOfMonth(),
                $date->copy()->endOfMonth(),
                $date->format('F Y'),
            ],
            'weekly' => [
                $date->copy()->startOfWeek(),
                $date->copy()->endOfWeek(),
                'Week of '.$date->copy()->startOfWeek()->format('j M Y'),
            ],
            default => [$date->copy(), $date->copy(), $date->format('j M Y')],
        };

        return [
            'key' => $this->bucketKey($granularity, $date),
            'label' => $label,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'sales_cents' => 0,
            'expenses_cents' => 0,
            'entry_count' => 0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $series
     * @return array<string, mixed>|null
     */
    private function bestBucket(array $series): ?array
    {
        $earning = array_filter($series, static fn (array $b): bool => $b['sales_cents'] > 0);

        if ($earning === []) {
            return null;
        }

        usort($earning, static fn (array $a, array $b): int => $b['sales_cents'] <=> $a['sales_cents']);

        return $earning[0];
    }

    /** @param array<string, mixed> $row */
    public function hasActivity(array $row): bool
    {
        return $row['trip_income_cents'] !== 0 || $row['total_expenses_cents'] !== 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{cents: int, trucks: int}
     */
    public function averageProfitPerTruck(array $rows): array
    {
        $active = array_values(array_filter($rows, fn (array $r): bool => $this->hasActivity($r)));

        if ($active === []) {
            return ['cents' => 0, 'trucks' => 0];
        }

        $net = array_sum(array_column($active, 'net_income_cents'));

        return ['cents' => (int) round($net / count($active)), 'trucks' => count($active)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    public function bestPerformer(array $rows): ?array
    {
        $earning = array_filter($rows, static fn (array $r): bool => $r['net_income_cents'] > 0);

        if ($earning === []) {
            return null;
        }

        usort($earning, static fn (array $a, array $b): int => $b['net_income_cents'] <=> $a['net_income_cents']);

        return $earning[0];
    }

    public function createEntry(LedgerEntryData $data, ?int $userId): LedgerEntry
    {
        if ((int) ($data->fuel_cents ?? 0) !== 0 && $data->truck_id !== null && $data->date !== null
            && $this->fillsPostedTo($data->truck_id, Carbon::parse($data->date))) {
            throw ValidationException::withMessages([
                'fuel_cents' => 'Fuel for this truck on this day is logged at Fuel Monitoring. Log the fill there instead of typing it on the sheet.',
            ]);
        }

        return DB::transaction(function () use ($data, $userId): LedgerEntry {
            $entry = $this->ledger->create($data->recordedBy($userId));
            $this->writeHelpers($entry, $data);

            return $entry->refresh();
        });
    }

    public function updateEntry(LedgerEntry $entry, LedgerEntryData $data): LedgerEntry
    {
        $this->refuseEditsToPostedFigures($entry, $data);

        return DB::transaction(function () use ($entry, $data): LedgerEntry {
            $updated = $this->ledger->update($entry, $data);
            $this->writeHelpers($updated, $data);

            return $updated->refresh();
        });
    }

    private function refuseEditsToPostedFigures(LedgerEntry $entry, LedgerEntryData $data): void
    {
        $posted = $this->postedOn($entry);

        if (! in_array(true, $posted, true)) {
            return;
        }

        $changes = static fn (string $key, mixed $current): bool => $data->wasGiven($key)
            && (string) ($data->{$key} ?? '') !== (string) ($current ?? '');

        $errors = [];

        if ($changes('date', $entry->date?->toDateString())
            && Carbon::parse((string) $data->date)->toDateString() !== $entry->date?->toDateString()) {
            $errors['date'] = 'This day carries figures posted from a trip, a maintenance job or a fuel fill. Change the date on that record instead.';
        }

        if ($changes('truck_id', $entry->truck_id)) {
            $errors['truck_id'] = 'This day carries figures posted from a trip, a maintenance job or a fuel fill. Move that record to the other unit instead.';
        }

        if ($posted['maintenance'] && $changes('maintenance_cents', (int) $entry->maintenance_cents)) {
            $errors['maintenance_cents'] = 'Maintenance on this day was posted from a service job. Correct the job under Truck Maintenance.';
        }

        if ($posted['trip_income'] && $changes('trip_income_cents', (int) $entry->trip_income_cents)) {
            $errors['trip_income_cents'] = 'Income on this day was posted from a delivered trip. Correct the trip instead.';
        }

        if ($posted['fuel'] && $changes('fuel_cents', (int) $entry->fuel_cents)) {
            $errors['fuel_cents'] = 'Fuel for this day is logged at Fuel Monitoring. Correct the fill there.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array{maintenance: bool, trip_income: bool, fuel: bool} */
    public function postedOn(LedgerEntry $entry): array
    {
        $day = $entry->date?->toDateString();
        $vehicleId = Truck::query()->whereKey($entry->truck_id)->value('vehicle_id');

        if ($day === null) {
            return ['maintenance' => false, 'trip_income' => false, 'fuel' => false];
        }

        return [
            'maintenance' => $vehicleId !== null && MaintenanceJob::query()
                ->where('vehicle_id', $vehicleId)
                ->where('posted_cents', '>', 0)
                ->whereDate('completed_on', $day)
                ->exists(),
            'trip_income' => $vehicleId !== null && Trip::query()
                ->where('vehicle_id', $vehicleId)
                ->whereNull('trucker_id')
                ->whereNotNull('billed_at')
                ->where('price_cents', '>', 0)
                ->whereDate('billed_at', $day)
                ->exists(),
            'fuel' => $this->fillsPostedTo((string) $entry->truck_id, $entry->date),
        ];
    }

    private function fillsPostedTo(string $truckId, CarbonInterface $date): bool
    {
        return FuelRecord::query()
            ->where('posted_truck_id', $truckId)
            ->where('posted_cents', '>', 0)
            ->whereDate('posted_on', $date->toDateString())
            ->exists();
    }

    private function writeHelpers(LedgerEntry $entry, LedgerEntryData $data): void
    {
        if ($data->wasGiven('helpers')) {
            $this->syncHelperLines($entry, $data->helpers ?? []);

            return;
        }

        if (! $data->wasGiven('helper_salary_cents')) {
            return;
        }

        $existing = $entry->helpers()->get();

        if ($existing->count() > 1) {
            throw ValidationException::withMessages([
                'helper_salary_cents' => 'This day has more than one helper, each paid separately. '
                    .'Enter each helper’s pay on the sheet, or update the app.',
            ]);
        }

        $cents = (int) $data->helper_salary_cents;
        $driverId = $existing->first()?->driver_id;

        $this->syncHelperLines($entry, $cents === 0 && $driverId === null
            ? []
            : [['driver_id' => $driverId, 'salary_cents' => $cents]]);
    }

    /** @param array<int, array{driver_id: ?string, salary_cents: int}> $lines */
    public function syncHelperLines(LedgerEntry $entry, array $lines): void
    {
        $entry->helpers()->delete();

        foreach (array_values($lines) as $position => $line) {
            $entry->helpers()->create([
                'driver_id' => $line['driver_id'] ?? null,
                'salary_cents' => (int) ($line['salary_cents'] ?? 0),
                'position' => $position,
            ]);
        }

        $entry->forceFill([
            'helper_salary_cents' => (int) array_sum(array_column($lines, 'salary_cents')),
        ])->save();

        $entry->unsetRelation('helpers');
    }

    public function deleteEntry(LedgerEntry $entry): void
    {
        if (in_array(true, $this->postedOn($entry), true)) {
            abort(422, 'This day carries figures posted from a trip, a maintenance job or a fuel fill. Change or remove those records instead; the day follows them.');
        }

        $this->ledger->delete($entry);
    }

    public function openDailyRow(
        string $vehicleId,
        ?string $plate,
        string $tripId,
        string $route,
        CarbonInterface $date,
        ?string $customerId = null,
        ?string $driverId = null,
        /** @var string[] */
        array $helperIds = [],
    ): LedgerEntry {
        $truck = $this->truckForVehicle($vehicleId, $plate);

        $row = $this->openDailyRowForTruck($truck->id, $date, [
            'trip_id' => $tripId,
            'customer_id' => $customerId,
            'driver_id' => $driverId,
            'route' => $route,
        ]);

        if ($row->wasRecentlyCreated && $helperIds !== []) {
            $this->syncHelperLines($row, array_map(
                static fn (string $id): array => ['driver_id' => $id, 'salary_cents' => 0],
                array_values(array_unique($helperIds)),
            ));
        }

        return $row;
    }

    /** @param array<string, mixed> $attributes */
    public function openDailyRowForTruck(string $truckId, CarbonInterface $date, array $attributes = []): LedgerEntry
    {
        $existing = LedgerEntry::where('truck_id', $truckId)
            ->whereDate('date', $date->toDateString())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return LedgerEntry::create([
            ...$attributes,
            'truck_id' => $truckId,
            'date' => $date->toDateString(),
        ]);
    }

    public function creditTripIncome(
        string $vehicleId,
        ?string $plate,
        string $tripId,
        string $route,
        CarbonInterface $date,
        int $incomeCents,
        ?string $customerId = null,
        ?string $driverId = null,
        /** @var string[] */
        array $helperIds = [],
        int $ownerShareCents = 0,
    ): LedgerEntry {
        $row = $this->openDailyRow(
            vehicleId: $vehicleId,
            plate: $plate,
            tripId: $tripId,
            route: $route,
            date: $date,
            customerId: $customerId,
            driverId: $driverId,
            helperIds: $helperIds,
        );

        if ($incomeCents !== 0) {
            $row->increment('trip_income_cents', $incomeCents);
        }

        if ($ownerShareCents !== 0) {
            $row->increment('owner_share_cents', $ownerShareCents);
        }

        return $row->refresh();
    }

    public function chargeMaintenance(
        string $vehicleId,
        ?string $plate,
        CarbonInterface $date,
        int $deltaCents,
    ): ?LedgerEntry {
        if ($deltaCents === 0) {
            return null;
        }

        $truck = $this->truckForVehicle($vehicleId, $plate);

        $row = $this->openDailyRowForTruck($truck->id, $date);

        $deltaCents > 0
            ? $row->increment('maintenance_cents', $deltaCents)
            : $row->update([
                'maintenance_cents' => max(0, (int) $row->maintenance_cents + $deltaCents),
            ]);

        return $row->refresh();
    }

    public function chargeFuel(string $truckId, CarbonInterface $date, int $deltaCents): ?LedgerEntry
    {
        if ($deltaCents === 0) {
            return null;
        }

        $row = $deltaCents > 0
            ? $this->openDailyRowForTruck($truckId, $date)
            : LedgerEntry::where('truck_id', $truckId)->whereDate('date', $date->toDateString())->first();

        if ($row === null) {
            return null;
        }

        $deltaCents > 0
            ? $row->increment('fuel_cents', $deltaCents)
            : $row->update(['fuel_cents' => max(0, (int) $row->fuel_cents + $deltaCents)]);

        return $row->refresh();
    }

    private function truckForVehicle(string $vehicleId, ?string $plate): Truck
    {
        $existing = Truck::where('vehicle_id', $vehicleId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $position = (int) Truck::max('position') + 1;

        return Truck::create([
            'label' => "Truck {$position}",
            'plate' => $plate,
            'vehicle_id' => $vehicleId,
            'position' => $position,
        ]);
    }
}
