<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Services;

use App\Domain\Delivery\DTO\ProofData;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Delivery\Repositories\DeliveryLogRepository;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use App\Domain\Trip\Services\TripService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DeliveryService extends CrudService
{
    public function __construct(
        private readonly DeliveryLogRepository $logs,
        private readonly ProofStore $proofs,
        private readonly TripService $trips,
    ) {}

    protected function repository(): Repository
    {
        return $this->logs;
    }

    public function forDriver(string $driverId): Collection
    {
        return $this->logs->forDriver($driverId);
    }

    /**
     * Attach proof of delivery to a log.
     *
     * The reference is not a parameter any more: `DeliveryLog` assigns it on
     * save once `delivered_at` is set, so a log completed here gets the next
     * number in the `POD-` series rather than whatever the caller supplied.
     *
     * **A log whose trip is still open is closed through the trip**, not
     * around it. This route used to write the log and set the trip's status to
     * `delivered` itself, which meant a run closed this way skipped everything
     * else delivering does — the dispatch record stayed open, the driver went
     * uncredited, the day's income never reached the ledger and the customer
     * was never invoiced. Two paths to `delivered` that did different amounts
     * of work is exactly the kind of split that leaves a month's books short
     * and nobody able to say which runs are missing. So this defers to
     * `TripService::complete`, and there is one hand-off in the system.
     *
     * What stays here is the genuinely different case: proof arriving *after*
     * the run was already closed — a photograph that would not upload from the
     * gate, sent again from somewhere with signal. That writes the evidence and
     * nothing else, because the money moved when the run closed.
     */
    public function attachProof(DeliveryLog $log, ProofData $proof): DeliveryLog
    {
        $trip = $log->trip;

        if ($trip !== null && $trip->status !== StatusValue::Delivered) {
            $this->trips->complete($trip, $proof);

            return $log->refresh();
        }

        return DB::transaction(function () use ($log, $proof): DeliveryLog {
            $log->fill([
                'receiver_name' => $proof->receiver_name,
                'delivered_at' => $log->delivered_at ?? now(),
                'status' => StatusValue::Delivered->value,
            ]);

            // Only written when a photograph actually arrived. Assigning null
            // over an existing path would delete the evidence, so a second
            // attempt cannot wipe the first.
            $path = $this->proofs->store($proof->photo);

            if ($path !== null) {
                $log->pod_image_path = $path;
            }

            $log->save();

            return $log->refresh();
        });
    }

    /**
     * The delivery report DESIGN.md section 5.1 asks for: pending, active and
     * complete side by side.
     *
     * @return array<string, int>
     */
    public function report(): array
    {
        $counts = DeliveryLog::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'pending' => (int) ($counts[StatusValue::Pending->value] ?? 0),
            'active' => (int) ($counts[StatusValue::InTransit->value] ?? 0),
            'complete' => (int) ($counts[StatusValue::Delivered->value] ?? 0),
            'cancelled' => (int) ($counts[StatusValue::Cancelled->value] ?? 0),
        ];
    }
}
