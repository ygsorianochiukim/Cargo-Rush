<?php

declare(strict_types=1);

namespace App\Domain\Incident\Services;

use App\Domain\Incident\DTO\IncidentData;
use App\Domain\Incident\Models\Incident;
use App\Domain\Incident\Repositories\IncidentRepository;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\Tone;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class IncidentService
{
    public function __construct(
        private readonly IncidentRepository $incidents,
        private readonly NotificationService $notifications,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->incidents->paginate($filters, $perPage);
    }

    public function forDriver(string $driverId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->incidents->paginateForDriver($driverId, $perPage);
    }

    public function report(IncidentData $data): Incident
    {
        return DB::transaction(function () use ($data): Incident {
            $incident = $this->incidents->create($data)->refresh();

            $this->notifications->push(
                icon: 'incident',
                title: "Incident {$incident->reference} raised",
                detail: trim(($incident->driver?->name ?? 'A driver')." reported {$incident->kind} at {$incident->place}"),
                tone: Tone::Danger,
            );

            return $incident;
        });
    }

    public function update(Incident $incident, IncidentData $data): Incident
    {
        return $this->incidents->update($incident, $data);
    }

    public function delete(Incident $incident): void
    {
        $this->incidents->delete($incident);
    }

    public function openCount(): int
    {
        return $this->incidents->openCount();
    }
}
