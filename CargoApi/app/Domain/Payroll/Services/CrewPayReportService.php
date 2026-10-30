<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Hr\Models\Employee;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\PayRunLine;
use App\Domain\Payroll\Models\PayRunLineTrip;
use App\Domain\Payroll\Support\MonthlyShare;
use App\Domain\Payroll\Support\PayPeriod;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Shared\Enums\CrewEmployment;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\PayrollScope;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\VehicleDriverAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CrewPayReportService
{
    public function __construct(
        private readonly HiredOutCrew $hiredOut,
        private readonly TripPayService $tripPay,
        private readonly Tenant $tenant,
    ) {}

    /** @return list<array<string, mixed>> */
    public function roster(): array
    {
        $calendar = PayrollCalendar::for($this->tenant->company());
        $closed = $calendar->justClosed();

        $people = Employee::query()
            ->whereNotNull('driver_id')
            ->whereHas('driver', fn ($driver) => $driver->where('employment', CrewEmployment::Company->value))
            ->with(['driver:id,name,role,status,licence_no', 'contracts' => fn ($contracts) => $contracts->inForceOn($closed->end)])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $sheet = $this->tripPay->forEmployees($people, $closed->start, $closed->end);

        $holding = VehicleDriverAssignment::query()
            ->open()
            ->onHiredTrucks()
            ->whereIn('driver_id', $people->pluck('driver_id')->all())
            ->with(['vehicle' => static fn ($vehicle) => $vehicle->withTrashed()->select(['id', 'plate', 'arrangement'])])
            ->get()
            ->keyBy('driver_id');

        return $people->map(function (Employee $employee) use ($sheet, $holding, $closed): array {
            $contract = $employee->contractOn($closed->end);
            $basis = $contract?->pay_basis ?? PayBasis::Monthly;
            $held = $holding->get($employee->driver_id);
            $worked = $sheet[$employee->getKey()] ?? ['trips' => 0, 'days' => 0];

            return [
                'employee_id' => $employee->getKey(),
                'driver_id' => $employee->driver_id,
                'employee_no' => $employee->employee_no,
                'name' => trim($employee->first_name.' '.$employee->last_name),
                'role' => $employee->driver?->role?->value ?? 'driver',
                'role_label' => $employee->driver?->role?->label() ?? 'Driver',
                'status' => $employee->status?->value,
                'pay_basis' => $basis->value,
                'pay_basis_label' => $basis->label(),
                'rate_cents' => (int) ($contract?->amount_cents ?? 0),
                'holding_plate' => $held?->vehicle?->plate,
                'holding_arrangement_label' => $held?->vehicle?->terms()->label(),
                'holding_since' => $held?->from_date?->toDateString(),
                'last_cutoff' => [
                    'label' => $closed->label(),
                    'trips' => $worked['trips'],
                    'days' => $worked['days'],
                ],
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public function forEmployee(Employee $employee, Carbon $from, Carbon $to): array
    {
        $calendar = PayrollCalendar::for($this->tenant->company());
        $driverId = $employee->driver_id;

        $periods = $this->periodsBetween($calendar, $from, $to);
        $start = $periods === [] ? $from : $periods[0]->start;
        $end = $periods === [] ? $to : end($periods)->end;

        $spans = $driverId === null ? [] : ($this->hiredOut->spansFor([$driverId], $start, $end)[$driverId] ?? []);
        $trips = $driverId === null ? collect() : $this->tripsFor($driverId, $start, $end);
        $sheet = $driverId === null ? collect() : $this->sheetFor($driverId, $start, $end);
        $runs = $this->crewRunsFor($employee, $start, $end);
        $settled = $this->settledTrips($employee, $trips->modelKeys());

        $cutoffs = [];

        foreach ($periods as $period) {
            $contract = $employee->contractOn($period->end);
            $basis = $contract?->pay_basis ?? PayBasis::Monthly;
            $rate = (int) ($contract?->amount_cents ?? 0);

            $inside = static fn (Carbon $day): bool => $day->between($period->start->copy()->startOfDay(), $period->end->copy()->endOfDay());

            $tripRows = $trips
                ->filter(fn (Trip $trip): bool => $inside($this->dayOf($trip)))
                ->map(function (Trip $trip) use ($spans, $driverId, $settled): array {
                    $day = $this->dayOf($trip);
                    $withheld = HiredOutCrew::covers($spans, $day);
                    $held = $withheld ? $this->spanOn($spans, $day) : null;
                    $paidOn = $settled[$trip->getKey()] ?? null;

                    return [
                        'id' => $trip->id,
                        'reference' => $trip->reference,
                        'on' => $day->toDateString(),
                        'origin' => $trip->origin,
                        'destination' => $trip->destination,
                        'plate' => $trip->vehicle?->plate,
                        'seat' => $trip->driver_id === $driverId ? 'driver' : 'helper',
                        'driver_allowance_cents' => (int) $trip->driver_allowance_cents,
                        'helper_allowance_cents' => (int) $trip->helper_allowance_cents,
                        'counted' => ! $withheld,
                        'withheld_reason' => $held === null ? null : sprintf(
                            'Assigned to %s (%s) %s–%s',
                            $held['plate'] ?? 'a hired truck',
                            $held['arrangement_label'],
                            $held['from']->format('j M'),
                            $held['to']->format('j M'),
                        ),
                        'paid_on_run' => $paidOn['reference'] ?? null,
                        'paid_on_run_status' => $paidOn['status'] ?? null,
                    ];
                })
                ->sortBy('on')
                ->values();

            $dayRows = $sheet
                ->filter(fn (array $row): bool => $inside(Carbon::parse($row['on'])))
                ->map(function (array $row) use ($spans): array {
                    $withheld = HiredOutCrew::covers($spans, Carbon::parse($row['on']));

                    return [...$row, 'counted' => ! $withheld];
                })
                ->sortBy('on')
                ->values();

            $countedTrips = $tripRows->where('counted', true)->count();
            $countedDays = $dayRows->where('counted', true)->pluck('on')->unique()->count();

            $expected = match ($basis) {
                PayBasis::PerTrip => $countedTrips * $rate,
                PayBasis::Daily => $countedDays * $rate,
                default => MonthlyShare::forRun($rate, $period->index, $period->count),
            };

            $run = $runs->first(fn (PayRun $candidate): bool => $candidate->period_start->isSameDay($period->start)
                && $candidate->period_end->isSameDay($period->end));
            $line = $run?->lines->first(fn (PayRunLine $line): bool => $line->employee_id === $employee->getKey());

            $heldWhole = HiredOutCrew::coversWhole($spans, $period->start, $period->end);

            $cutoffs[] = [
                'label' => $period->label(),
                'short' => $period->short(),
                'start' => $period->start->toDateString(),
                'end' => $period->end->toDateString(),
                'pay_basis' => $basis->value,
                'pay_basis_label' => $basis->label(),
                'rate_cents' => $rate,
                'trips' => $tripRows->all(),
                'sheet_days' => $dayRows->all(),
                'counted_trips' => $countedTrips,
                'withheld_trips' => $tripRows->count() - $countedTrips,
                'counted_days' => $countedDays,
                'expected_cents' => $expected,
                'held_whole_cutoff' => $heldWhole,
                'hired_out' => array_values(array_map(static fn (array $span): array => [
                    'plate' => $span['plate'],
                    'arrangement_label' => $span['arrangement_label'],
                    'from' => $span['from']->toDateString(),
                    'to' => $span['to']->toDateString(),
                ], array_filter($spans, static fn (array $span): bool => $span['from']->lte($period->end) && $span['to']->gte($period->start)))),
                'run' => $run === null ? null : [
                    'id' => $run->id,
                    'reference' => $run->reference,
                    'status' => $run->status,
                    'on_run' => $line !== null,
                    'basic_cents' => $line?->basic_cents,
                    'gross_cents' => $line?->gross_cents,
                    'net_cents' => $line?->net_cents,
                    'trips' => $line?->trips,
                    'days_worked' => $line?->days_worked,
                    'agrees' => $line === null ? null : (int) $line->basic_cents === $expected,
                ],
            ];
        }

        $contract = $employee->contractOn($to);

        return [
            'employee' => [
                'employee_id' => $employee->getKey(),
                'driver_id' => $driverId,
                'employee_no' => $employee->employee_no,
                'name' => trim($employee->first_name.' '.$employee->last_name),
                'role_label' => $employee->driver?->role?->label() ?? 'Driver',
                'pay_basis' => ($contract?->pay_basis ?? PayBasis::Monthly)->value,
                'pay_basis_label' => ($contract?->pay_basis ?? PayBasis::Monthly)->label(),
                'rate_cents' => (int) ($contract?->amount_cents ?? 0),
            ],
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'cutoffs' => array_reverse($cutoffs),
            'totals' => [
                'trips' => array_sum(array_column($cutoffs, 'counted_trips')),
                'withheld_trips' => array_sum(array_column($cutoffs, 'withheld_trips')),
                'days' => array_sum(array_column($cutoffs, 'counted_days')),
                'expected_cents' => array_sum(array_column($cutoffs, 'expected_cents')),
            ],
        ];
    }

    /** @return list<PayPeriod> */
    private function periodsBetween(PayrollCalendar $calendar, Carbon $from, Carbon $to): array
    {
        return $calendar->between($from, $to);
    }

    /** @return Collection<int, Trip> */
    private function tripsFor(string $driverId, Carbon $from, Carbon $to): Collection
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        return Trip::query()
            ->where('status', StatusValue::Delivered->value)
            ->where(fn ($query) => $query
                ->where('driver_id', $driverId)
                ->orWhereHas('helpers', fn ($helper) => $helper->where('drivers.id', $driverId)))
            ->where(fn ($query) => $query
                ->whereHas('deliveryLog', fn ($log) => $log->whereBetween('delivered_at', [$start, $end]))
                ->orWhere(fn ($noProof) => $noProof->whereDoesntHave('deliveryLog')->whereBetween('scheduled_at', [$start, $end])))
            ->with(['vehicle:id,plate', 'deliveryLog:id,trip_id,delivered_at'])
            ->get();
    }

    /** @return Collection<int, array{on: string, truck: string|null, salary_cents: int, seat: string}> */
    private function sheetFor(string $driverId, Carbon $from, Carbon $to): Collection
    {
        return LedgerEntry::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->where(fn ($query) => $query
                ->where('driver_id', $driverId)
                ->orWhereHas('helpers', fn ($line) => $line->where('driver_id', $driverId)))
            ->with(['helpers:id,ledger_entry_id,driver_id,salary_cents', 'truck:id,label,plate'])
            ->get()
            ->map(function (LedgerEntry $row) use ($driverId): array {
                $asDriver = $row->driver_id === $driverId;
                $helper = $asDriver ? null : $row->helpers->firstWhere('driver_id', $driverId);

                return [
                    'on' => $row->date->toDateString(),
                    'truck' => $row->truck?->label ?? $row->truck?->plate,
                    'salary_cents' => (int) ($asDriver ? $row->driver_salary_cents : ($helper?->salary_cents ?? 0)),
                    'seat' => $asDriver ? 'driver' : 'helper',
                ];
            });
    }

    /** @return Collection<int, PayRun> */
    private function crewRunsFor(Employee $employee, Carbon $from, Carbon $to): Collection
    {
        return PayRun::query()
            ->ofScope(PayrollScope::Crew)
            ->whereDate('period_end', '>=', $from->toDateString())
            ->whereDate('period_start', '<=', $to->toDateString())
            ->with(['lines' => fn ($lines) => $lines->where('employee_id', $employee->getKey())])
            ->get();
    }

    /**
     * @param  string[]  $tripIds
     * @return array<string, array{reference: string, status: string}>
     */
    private function settledTrips(Employee $employee, array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        $paid = [];

        PayRunLineTrip::query()
            ->where('employee_id', $employee->getKey())
            ->whereIn('trip_id', $tripIds)
            ->with('line.payRun:id,reference,status')
            ->get()
            ->each(function (PayRunLineTrip $link) use (&$paid): void {
                $run = $link->line?->payRun;

                if ($run === null) {
                    return;
                }

                $current = $paid[$link->trip_id] ?? null;

                if ($current === null || $run->status !== PayRun::DRAFT) {
                    $paid[$link->trip_id] = ['reference' => $run->reference, 'status' => $run->status];
                }
            });

        return $paid;
    }

    private function dayOf(Trip $trip): Carbon
    {
        return Carbon::parse($trip->deliveryLog?->delivered_at ?? $trip->scheduled_at);
    }

    /**
     * @param  list<array{from: Carbon, to: Carbon, plate: string|null, arrangement_label: string}>  $spans
     * @return array{from: Carbon, to: Carbon, plate: string|null, arrangement_label: string}|null
     */
    private function spanOn(array $spans, Carbon $day): ?array
    {
        foreach ($spans as $span) {
            if ($span['from']->lte($day) && $span['to']->gte($day)) {
                return $span;
            }
        }

        return null;
    }
}
