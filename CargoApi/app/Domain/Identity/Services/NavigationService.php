<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Hr\Repositories\ApplicantRepository;
use App\Domain\Hr\Repositories\TimeOffRepository;
use App\Domain\Identity\Models\NavItem;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Repositories\NavigationRepository;
use App\Domain\Incident\Repositories\IncidentRepository;
use App\Domain\Notification\Repositories\NotificationRepository;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Repositories\TripRepository;
use App\Domain\Trucker\Repositories\TruckerRepository;
use App\Domain\Trucker\Services\JobBoardService;
use Illuminate\Support\Collection;

class NavigationService
{
    public function __construct(
        private readonly NavigationRepository $navigation,
        private readonly TripRepository $trips,
        private readonly IncidentRepository $incidents,
        private readonly NotificationRepository $notifications,
        private readonly ApplicantRepository $applicants,
        private readonly TimeOffRepository $timeOff,
        private readonly TruckerRepository $truckers,
        private readonly JobBoardService $jobs,
    ) {}

    /**
     * @param  'web'|'mobile'  $client
     * @return Collection<int, array<string, mixed>>
     */
    public function forUser(User $user, string $client = 'web'): Collection
    {
        $badges = $this->badges($user);

        return $this->navigation
            ->forClient($client, $user->permissions(), $this->navigation->assignedKeys($user->id))
            ->map(fn (NavItem $item): array => [
                'key' => $item->key,
                'label' => $item->label,
                'icon' => $item->icon,
                'route' => $item->route,
                'order' => $item->order,
                'mobile' => $item->mobile,
                'group' => $item->group,
                'badge' => $this->badge($item, $badges),
            ]);
    }

    /** @param array<string, int> $badges */
    private function badge(NavItem $item, array $badges): ?int
    {
        if ($item->badge_source === null) {
            return null;
        }

        $count = $badges[$item->badge_source] ?? 0;

        return $count > 0 ? $count : null;
    }

    /** @return array<string, int> */
    private function badges(User $user): array
    {
        $tripCounts = $this->trips->countsByStatus();

        return [
            'trips.requests' => $tripCounts[StatusValue::Pending->value] ?? 0,
            'trips.pending' => ($tripCounts[StatusValue::Pending->value] ?? 0)
                + ($tripCounts[StatusValue::Assigned->value] ?? 0),
            'trips.overdue' => $tripCounts[StatusValue::Overdue->value] ?? 0,
            'incidents.open' => $this->incidents->openCount(),
            'notifications.unread' => $this->notifications->unreadCount($user->id),
            'applicants.open' => $this->applicants->openCount(),
            'timeoff.open' => $this->timeOff->openCount(),

            'truckers.pending' => $this->truckers->pendingCount(),

            'partner.jobs' => $this->openJobsFor($user),
        ];
    }

    private function openJobsFor(User $user): int
    {
        if ($user->role !== Role::Trucker->value) {
            return 0;
        }

        $trucker = $this->truckers->findByUser((int) $user->getKey());

        return $trucker === null ? 0 : $this->jobs->open($trucker)->count();
    }
}
