<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Services;

use App\Domain\Finance\Services\FinanceService;
use App\Domain\Vehicle\Models\MaintenanceJob;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Repositories\MaintenanceRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Servicing a unit, and what it cost.
 *
 * A maintenance job used to record only that something was *due*. What it came
 * to had to be filed as an expense with a truck hung off it, on the Other
 * Expenses screen — so the service history and the money were two records of
 * one event, kept in two modules, reconciled by nobody. An office asking "what
 * has this truck cost us this year" had to open both and hope.
 *
 * A job now carries its own cost, and this is what puts that figure where the
 * rest of the fleet's money already lives: `maintenance_cents` on the unit's
 * daily sheet, which is one of the five workbook columns and has always been
 * counted per truck by Profitability and the Quarterly Summary.
 *
 * ## Posting it exactly once, however many times it is saved
 *
 * The hard part is not the first save, it is the fourth. A cost is corrected,
 * a date is moved, somebody presses save twice — and every one of those must
 * leave the sheet holding the job's cost once.
 *
 * `posted_cents` is the answer: the figure already pushed. Every save applies
 * the **difference**, so
 *
 *     ₱3,200 entered   → sheet +3,200,  posted 3,200
 *     corrected 3,500  → sheet   +300,  posted 3,500
 *     saved again      → sheet     +0,  posted 3,500
 *     cost cleared     → sheet −3,500,  posted 0
 *
 * and moving the date takes the whole figure off the old day before putting it
 * on the new one, because those are two different rows.
 *
 * It is the same shape as the guard on a delivery (`trips.billed_at`) and the
 * same reason: an additive credit is the kind that is wrong twice over if it
 * runs twice.
 */
class MaintenanceService
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly MaintenanceRepository $jobs,
    ) {}

    /**
     * Servicing across the fleet — what Truck Maintenance lists.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->jobs->paginate($filters, $perPage);
    }

    /**
     * What the filtered jobs have cost, in centavos.
     *
     * Sent beside the page rather than added up in the client, because a client
     * can only total the rows it was given and the answer a spend screen is
     * asked for is about the whole window, not the first twenty-five of it.
     *
     * @param  array<string, mixed>  $filters
     */
    public function costedTotal(array $filters = []): int
    {
        return $this->jobs->costedBetween($filters);
    }

    public function find(string $id): MaintenanceJob
    {
        /** @var MaintenanceJob */
        return $this->jobs->findOrFail($id);
    }

    /**
     * Book or correct a job, and keep the sheet in step with it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function save(Vehicle $vehicle, array $attributes, ?MaintenanceJob $job = null): MaintenanceJob
    {
        return DB::transaction(function () use ($vehicle, $attributes, $job): MaintenanceJob {
            /**
             * A job that has changed hands — a receipt keyed against the wrong
             * plate, corrected.
             *
             * Taken off the old unit in full before anything else, because
             * `settle()` below reasons about **one** unit's sheet: handed the
             * new truck, it would credit the new truck for a figure the old one
             * is still carrying, and both would then be wrong. Only Truck
             * Maintenance can reach this — the nested route resolves the job
             * off the vehicle in its path, so there the unit is the one thing
             * that cannot change.
             */
            if ($job !== null && $job->vehicle_id !== $vehicle->getKey()) {
                $this->takeOffTheSheet($job);

                $job->forceFill(['vehicle_id' => $vehicle->getKey()])->save();
                $job = $job->refresh();
            }

            // The day the figure currently sits on, read before anything moves.
            // A job whose date changed has to be taken off the old row, and
            // after the update there is no way left to find out which that was.
            $postedOn = $job?->completed_on;
            $posted = (int) ($job?->posted_cents ?? 0);

            $job = $job === null
                ? $vehicle->maintenanceJobs()->create($attributes)
                : tap($job)->update($attributes);

            $this->settle($vehicle, $job, $posted, $postedOn);

            return $job->refresh();
        });
    }

    /**
     * Take a job back off the books, then remove it.
     *
     * The sheet is credited back first. A deleted job that left its cost on the
     * daily row would overstate what the unit cost for the rest of time, and
     * nothing on the sheet would say where the figure came from.
     */
    public function remove(MaintenanceJob $job): void
    {
        DB::transaction(function () use ($job): void {
            $this->takeOffTheSheet($job);

            $job->delete();
        });
    }

    /**
     * Credit the unit's sheet back whatever this job has put on it.
     *
     * Shared by the two things that have to do it in full rather than by
     * difference: deleting a job, and moving one to another truck. Both leave
     * `posted_cents` at nought, which is what makes the next save charge the
     * whole figure again wherever it now belongs.
     *
     * Silent on a job that never reached the sheet — one with no cost, no
     * completed date, or no unit left to credit.
     */
    private function takeOffTheSheet(MaintenanceJob $job): void
    {
        $vehicle = $job->vehicle;

        if ($vehicle !== null && (int) $job->posted_cents !== 0 && $job->completed_on !== null) {
            $this->finance->chargeMaintenance(
                $vehicle->getKey(),
                $vehicle->plate,
                $job->completed_on,
                -(int) $job->posted_cents,
            );
        }

        $job->forceFill(['posted_cents' => 0])->save();
    }

    /**
     * Move the sheet by whatever this save changed.
     *
     * Two cases, and keeping them apart is what makes a moved date correct: on
     * the **same day** it is a difference, and across days it is a full removal
     * followed by a full charge. Treating the second as a difference would
     * leave the old day holding money the job no longer claims.
     */
    private function settle(Vehicle $vehicle, MaintenanceJob $job, int $posted, ?Carbon $postedOn): void
    {
        // Not costed, or costed but not yet said to be done: nothing is charged
        // to a day that has not been named. Anything already posted comes back
        // off, which is what happens when somebody clears a figure they had
        // entered by mistake.
        $charge = $job->completed_on === null || ! $job->isCosted()
            ? 0
            : (int) $job->cost_cents;

        $sameDay = $postedOn !== null
            && $job->completed_on !== null
            && $postedOn->isSameDay($job->completed_on);

        if ($sameDay) {
            $this->finance->chargeMaintenance(
                $vehicle->getKey(),
                $vehicle->plate,
                $job->completed_on,
                $charge - $posted,
            );

            $job->forceFill(['posted_cents' => $charge])->save();

            return;
        }

        if ($posted !== 0 && $postedOn !== null) {
            $this->finance->chargeMaintenance($vehicle->getKey(), $vehicle->plate, $postedOn, -$posted);
        }

        if ($charge !== 0 && $job->completed_on !== null) {
            $this->finance->chargeMaintenance(
                $vehicle->getKey(),
                $vehicle->plate,
                $job->completed_on,
                $charge,
            );
        }

        $job->forceFill(['posted_cents' => $charge])->save();
    }
}
