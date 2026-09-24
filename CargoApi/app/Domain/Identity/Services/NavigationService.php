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

/**
 * The sidebar and the tab bar, as data.
 *
 * Badges are live counts resolved here rather than stored on the row, because
 * a stored badge is wrong the moment anything happens. `badge_source` on a nav
 * row names which count it wants; this class is the only thing that knows how
 * to produce one.
 */
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
            // The third argument is the module assignment from HR, and it can
            // only narrow: it is intersected with what the role already allows,
            // never added to it (`NavigationRepository::forClient`).
            ->forClient($client, $user->permissions(), $this->navigation->assignedKeys($user->id))
            ->map(fn (NavItem $item): array => [
                'key' => $item->key,
                'label' => $item->label,
                'icon' => $item->icon,
                'route' => $item->route,
                'order' => $item->order,
                'mobile' => $item->mobile,
                'group' => $item->group,
                // Absent or null means no badge, so a zero count is dropped
                // rather than rendered as a "0" pill.
                'badge' => $this->badge($item, $badges),
            ]);
    }

    /**
     * @param  array<string, int>  $badges
     */
    private function badge(NavItem $item, array $badges): ?int
    {
        if ($item->badge_source === null) {
            return null;
        }

        $count = $badges[$item->badge_source] ?? 0;

        return $count > 0 ? $count : null;
    }

    /**
     * Every count a nav row can ask for, in one pass.
     *
     * @return array<string, int>
     */
    private function badges(User $user): array
    {
        $tripCounts = $this->trips->countsByStatus();

        return [
            // Requests waiting on the desk to confirm them. This is what the
            // Trip Management badge counts now: `pending` means "nobody has
            // looked at this yet", and the number worth a red dot is the one
            // somebody has to act on, not the size of the driver's queue.
            'trips.requests' => $tripCounts[StatusValue::Pending->value] ?? 0,
            'trips.pending' => ($tripCounts[StatusValue::Pending->value] ?? 0)
                + ($tripCounts[StatusValue::Assigned->value] ?? 0),
            'trips.overdue' => $tripCounts[StatusValue::Overdue->value] ?? 0,
            'incidents.open' => $this->incidents->openCount(),
            'notifications.unread' => $this->notifications->unreadCount($user->id),
            // A CV sitting unread for a week is the failure the Applicants
            // module exists to prevent, so the count that earns a red dot is
            // the one still waiting on somebody.
            'applicants.open' => $this->applicants->openCount(),
            // Leave and undertime together: the desk works one queue, and a
            // driver waiting to hear about next Tuesday does not care which
            // table their request is in.
            'timeoff.open' => $this->timeOff->openCount(),

            /**
             * Partner registrations nobody has vetted yet.
             *
             * The same argument as the applicants' badge: somebody is sitting
             * in a waiting room with an empty job board until a human acts, and
             * a queue nobody is told about is a queue nobody works.
             */
            'truckers.pending' => $this->truckers->pendingCount(),

            /**
             * Work a partner could take right now — the badge on their own
             * Jobs tab.
             *
             * Counted only for an account that actually is one. Everybody
             * else's navigation would otherwise run a query to produce a number
             * no row of theirs can display, and the partner's tab set is
             * unreachable without a `truckers` row anyway.
             */
            'partner.jobs' => $this->openJobsFor($user),
        ];
    }

    /**
     * How many loads this partner could take, or zero for anybody who is not
     * one.
     *
     * Goes through the job board rather than counting `pending` trips, so the
     * badge counts exactly what the tab will show — a number that includes work
     * their truck cannot carry would send them to an emptier screen than the
     * dot promised, which is the fastest way to teach somebody to ignore a
     * badge.
     */
    private function openJobsFor(User $user): int
    {
        if ($user->role !== Role::Trucker->value) {
            return 0;
        }

        $trucker = $this->truckers->findByUser((int) $user->getKey());

        return $trucker === null ? 0 : $this->jobs->open($trucker)->count();
    }
}
