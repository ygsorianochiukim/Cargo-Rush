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

/**
 * Incident Management, and the Notification Management module it feeds.
 *
 * DESIGN.md section 5.1 calls Notifications "incident notification" — raising
 * one is what puts a row in the feed, so the two are wired together here
 * rather than left to whoever remembers.
 */
class IncidentService
{
    public function __construct(
        private readonly IncidentRepository $incidents,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->incidents->paginate($filters, $perPage);
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
