<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Services;

use App\Domain\Shared\Enums\VehicleArrangement;
use App\Domain\Vehicle\Models\VehicleDriverAssignment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class HiredOutCrew
{
    /**
     * @param  string[]  $driverIds
     * @return array<string, list<array{from: Carbon, to: Carbon, plate: string|null, arrangement: string, arrangement_label: string}>>
     */
    public function spansFor(array $driverIds, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($driverIds === []) {
            return [];
        }

        $start = Carbon::parse($from->toDateString());
        $end = Carbon::parse($to->toDateString());

        $spans = [];

        VehicleDriverAssignment::query()
            ->with(['vehicle' => static fn ($vehicle) => $vehicle->withTrashed()->select(['id', 'plate', 'arrangement'])])
            ->whereIn('driver_id', array_values(array_unique($driverIds)))
            ->overlapping($start, $end)
            ->onHiredTrucks()
            ->orderBy('from_date')
            ->get()
            ->each(function (VehicleDriverAssignment $row) use (&$spans, $start, $end): void {
                $terms = $row->vehicle?->terms() ?? VehicleArrangement::Owned;

                $spans[$row->driver_id][] = [
                    'from' => $row->from_date->lt($start) ? $start->copy() : $row->from_date->copy(),
                    'to' => $row->to_date === null || $row->to_date->gt($end) ? $end->copy() : $row->to_date->copy(),
                    'plate' => $row->vehicle?->plate,
                    'arrangement' => $terms->value,
                    'arrangement_label' => $terms->label(),
                ];
            });

        return $spans;
    }

    /** @param list<array{from: Carbon, to: Carbon}> $spans */
    public static function covers(array $spans, CarbonInterface $day): bool
    {
        $on = Carbon::parse($day->toDateString());

        foreach ($spans as $span) {
            if ($span['from']->lte($on) && $span['to']->gte($on)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{from: Carbon, to: Carbon}> $spans */
    public static function coversWhole(array $spans, CarbonInterface $from, CarbonInterface $to): bool
    {
        if ($spans === []) {
            return false;
        }

        usort($spans, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        $cursor = Carbon::parse($from->toDateString());
        $end = Carbon::parse($to->toDateString());

        foreach ($spans as $span) {
            if ($span['from']->gt($cursor)) {
                return false;
            }

            $next = $span['to']->copy()->addDay();

            if ($next->gt($cursor)) {
                $cursor = $next;
            }

            if ($cursor->gt($end)) {
                return true;
            }
        }

        return $cursor->gt($end);
    }
}
