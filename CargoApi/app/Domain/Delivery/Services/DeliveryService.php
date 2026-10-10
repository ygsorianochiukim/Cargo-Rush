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

            $path = $this->proofs->store($proof->photo);

            if ($path !== null) {
                $log->pod_image_path = $path;
            }

            $log->save();

            return $log->refresh();
        });
    }

    /** @return array<string, int> */
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
