<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Hr\Models\Employee;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * What one person's period looked like on the road.
 *
 * The bridge between operations and payroll, and the only thing in this module
 * that reads the workbook or the trip record. It answers three things about a
 * person and a fortnight, and payroll picks whichever its basis needs:
 *
 *   **days** — distinct days the truck sheet names them on. What a daily rate
 *   is multiplied by.
 *
 *   **trips** — hauls they delivered. What a per-trip rate is multiplied by.
 *
 *   **earned_cents** — what the sheet already recorded them earning. No longer
 *   what anybody is paid; see below.
 *
 * ## The sheet no longer decides what per-trip pay is
 *
 * It used to. A per-trip driver had no rate anywhere in this system, and their
 * payslip was `driver_salary` summed off the daily sheet. The argument was that
 * a fleet's per-trip arrangements are endless and a rate card would be wrong
 * within a month.
 *
 * What it cost was an answer to "what does a driver get per trip" — so every
 * hire was a fresh negotiation typed onto a sheet, and raising one driver meant
 * catching every future row somebody would write about them. The rate lives on
 * the contract now, and this counts the hauls it is multiplied by.
 *
 * `earned_cents` is still read and still returned, because it is still the
 * record of what the office actually spent on those days, and a payslip that
 * disagrees with it is worth being able to notice.
 *
 * ## Why trips are counted from the trip record, not the sheet
 *
 * A sheet row is one truck's **day**, and a truck can run several hauls in a
 * day. Counting rows would pay a driver once for a day they ran three.
 *
 * A trip counts toward the period it was **delivered** in, from the proof of
 * delivery, falling back to the scheduled date where there is no POD — the same
 * fallback `InvoiceDocumentService` bills on, so a haul lands on the same
 * fortnight in payroll as it does on the invoice.
 *
 * ## Driver and helpers on the same row
 *
 * A ledger row is one truck's day and carries a driver and any number of
 * helpers — the driver's pay in its column, each helper's on their own line.
 * The same person is never on one row twice, so the amounts are added rather
 * than chosen between — a relief driver who rode as a helper on Tuesday and
 * drove on Thursday is paid for both, from two rows. Trips are counted the
 * same way, from any seat.
 *
 * ## Unattributed rows count toward nobody
 *
 * A row with no `driver_id` — one entered by hand before the column existed, or
 * a day nobody named — is skipped rather than guessed at. That is the safe
 * direction: the failure becomes a figure somebody notices is missing, rather
 * than one quietly paid to the wrong person.
 */
class TripPayService
{
    /**
     * One person's days, trips and sheet earnings over a period.
     *
     * @return array{earned_cents: int, days: int, trips: int}
     */
    public function forEmployee(Employee $employee, Carbon $periodStart, Carbon $periodEnd): array
    {
        return $this->forEmployees(collect([$employee]), $periodStart, $periodEnd)[$employee->getKey()]
            ?? ['earned_cents' => 0, 'days' => 0, 'trips' => 0];
    }

    /**
     * The same question for a whole payroll, in a fixed number of queries.
     *
     * Building a run asks this once per person, and asking the database once
     * per person is how a 90-strong roster turns into ninety round trips for
     * two tables it could have read in a single pass each. The per-employee
     * method above is kept for the odd caller with one person in hand, and
     * delegates here so there is one implementation of the arithmetic rather
     * than two that can drift.
     *
     * Keyed by **employee** id rather than driver id, because that is what the
     * caller has and what the payslip is for — the driver id is an
     * implementation detail of where the work is recorded.
     *
     * @param  Collection<int, Employee>|SupportCollection<int, Employee>  $employees
     * @return array<string, array{earned_cents: int, days: int, trips: int}>
     */
    public function forEmployees($employees, Carbon $periodStart, Carbon $periodEnd): array
    {
        // Somebody with no `drivers` row has no work this could read. Not an
        // error: an office clerk has no such row and never will, and asking
        // this about them is a fair question with the answer "nothing".
        $byDriver = [];

        foreach ($employees as $employee) {
            if ($employee->driver_id !== null) {
                $byDriver[$employee->driver_id] = $employee->getKey();
            }
        }

        $totals = [];

        foreach ($employees as $employee) {
            $totals[$employee->getKey()] = ['earned_cents' => 0, 'days' => [], 'trips' => 0];
        }

        if ($byDriver === []) {
            return $this->countDays($totals);
        }

        $driverIds = array_keys($byDriver);

        $this->addSheet($totals, $byDriver, $driverIds, $periodStart, $periodEnd);
        $this->addTrips($totals, $byDriver, $driverIds, $periodStart, $periodEnd);

        return $this->countDays($totals);
    }

    /**
     * Days and sheet earnings, off the daily truck sheet.
     *
     * @param  array<string, array{earned_cents: int, days: array<string, bool>, trips: int}>  $totals
     * @param  array<string, string>  $byDriver
     * @param  string[]  $driverIds
     */
    private function addSheet(array &$totals, array $byDriver, array $driverIds, Carbon $periodStart, Carbon $periodEnd): void
    {
        $rows = LedgerEntry::query()
            ->whereDate('date', '>=', $periodStart->toDateString())
            ->whereDate('date', '<=', $periodEnd->toDateString())
            ->where(function ($query) use ($driverIds): void {
                $query->whereIn('driver_id', $driverIds)
                    ->orWhereHas('helpers', static fn ($line) => $line->whereIn('driver_id', $driverIds));
            })
            ->with('helpers:id,ledger_entry_id,driver_id,salary_cents')
            ->get(['id', 'date', 'driver_id', 'driver_salary_cents']);

        foreach ($rows as $row) {
            $day = $row->date->toDateString();

            $seats = [
                [$row->driver_id, (int) $row->driver_salary_cents],
                // Each helper's own line, not the day's helper total: two
                // helpers on one day are two people on two rates.
                ...$row->helpers->map(static fn ($line): array => [$line->driver_id, (int) $line->salary_cents])->all(),
            ];

            foreach ($seats as [$driverId, $amount]) {
                $employeeId = $driverId === null ? null : ($byDriver[$driverId] ?? null);

                if ($employeeId === null) {
                    continue;
                }

                $totals[$employeeId]['earned_cents'] += $amount;
                // Keyed by the date rather than counted, so a person who
                // appears on two trucks in one day — which happens, and is not
                // two days of work — is counted once.
                $totals[$employeeId]['days'][$day] = true;
            }
        }
    }

    /**
     * Hauls delivered in the period, from either seat.
     *
     * Counted per person rather than per trip row, so a driver and their helper
     * on the same haul each count it once — they were both on it, and both are
     * paid for it.
     *
     * Only `delivered` trips count. A haul still in transit is not yet work
     * done, and paying for it would mean clawing it back when it is cancelled.
     *
     * @param  array<string, array{earned_cents: int, days: array<string, bool>, trips: int}>  $totals
     * @param  array<string, string>  $byDriver
     * @param  string[]  $driverIds
     */
    private function addTrips(array &$totals, array $byDriver, array $driverIds, Carbon $periodStart, Carbon $periodEnd): void
    {
        $from = $periodStart->copy()->startOfDay();
        $to = $periodEnd->copy()->endOfDay();

        $trips = Trip::query()
            ->where('status', StatusValue::Delivered->value)
            ->where(function ($query) use ($driverIds): void {
                $query->whereIn('driver_id', $driverIds)
                    ->orWhereHas('helpers', static fn ($helper) => $helper->whereIn('drivers.id', $driverIds));
            })
            ->where(function ($query) use ($from, $to): void {
                // Delivered in the window, by the proof of delivery.
                $query->whereHas('deliveryLog', function ($log) use ($from, $to): void {
                    $log->whereBetween('delivered_at', [$from, $to]);
                });

                // No proof on file, so the date it was booked for is the best
                // record of when it ran — the same fallback the invoice uses.
                $query->orWhere(function ($noProof) use ($from, $to): void {
                    $noProof->whereDoesntHave('deliveryLog')
                        ->whereBetween('scheduled_at', [$from, $to]);
                });
            })
            ->with('helpers:drivers.id')
            ->get(['id', 'driver_id']);

        foreach ($trips as $trip) {
            foreach ([$trip->driver_id, ...$trip->helperIds()] as $driverId) {
                $employeeId = $driverId === null ? null : ($byDriver[$driverId] ?? null);

                if ($employeeId === null) {
                    continue;
                }

                $totals[$employeeId]['trips']++;
            }
        }
    }

    /**
     * Turn the day sets into counts.
     *
     * @param  array<string, array{earned_cents: int, days: array<string, bool>, trips: int}>  $totals
     * @return array<string, array{earned_cents: int, days: int, trips: int}>
     */
    private function countDays(array $totals): array
    {
        return array_map(
            static fn (array $row): array => [
                'earned_cents' => $row['earned_cents'],
                'days' => count($row['days']),
                'trips' => $row['trips'],
            ],
            $totals,
        );
    }
}
