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

class MaintenanceService
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly MaintenanceRepository $jobs,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->jobs->paginate($filters, $perPage);
    }

    /** @param array<string, mixed> $filters */
    public function costedTotal(array $filters = []): int
    {
        return $this->jobs->costedBetween($filters);
    }

    public function find(string $id): MaintenanceJob
    {
        /** @var MaintenanceJob */
        return $this->jobs->findOrFail($id);
    }

    /** @param array<string, mixed> $attributes */
    public function save(Vehicle $vehicle, array $attributes, ?MaintenanceJob $job = null): MaintenanceJob
    {
        return DB::transaction(function () use ($vehicle, $attributes, $job): MaintenanceJob {
            if ($job !== null && $job->vehicle_id !== $vehicle->getKey()) {
                $this->takeOffTheSheet($job);

                $job->forceFill(['vehicle_id' => $vehicle->getKey()])->save();
                $job = $job->refresh();
            }

            $postedOn = $job?->completed_on;
            $posted = (int) ($job?->posted_cents ?? 0);

            $job = $job === null
                ? $vehicle->maintenanceJobs()->create($attributes)
                : tap($job)->update($attributes);

            $this->settle($vehicle, $job, $posted, $postedOn);

            return $job->refresh();
        });
    }

    public function remove(MaintenanceJob $job): void
    {
        DB::transaction(function () use ($job): void {
            $this->takeOffTheSheet($job);

            $job->delete();
        });
    }

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

    private function settle(Vehicle $vehicle, MaintenanceJob $job, int $posted, ?Carbon $postedOn): void
    {
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
