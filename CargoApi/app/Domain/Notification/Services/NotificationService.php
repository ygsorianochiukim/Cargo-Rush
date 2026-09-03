<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Notification\Repositories\NotificationRepository;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\Tone;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationService
{
    public function __construct(private readonly NotificationRepository $notifications) {}

    public function paginate(int $userId, int $perPage = 25): LengthAwarePaginator
    {
        return $this->notifications->forUser($userId)->paginate($perPage);
    }

    /**
     * Put a row in the feed.
     *
     * `icon` is a name from the shared icon set — never a URL or an emoji
     * (DESIGN.md section 7.3) — and a null `$userId` means the whole fleet.
     */
    public function push(
        string $icon,
        string $title,
        string $detail,
        Tone $tone = Tone::Info,
        ?int $userId = null,
    ): NotificationItem {
        return NotificationItem::create([
            'user_id' => $userId,
            'icon' => $icon,
            'title' => $title,
            'detail' => $detail,
            'tone' => $tone->value,
            'read' => false,
        ]);
    }

    /**
     * Tell the people who can act on it.
     *
     * A fleet-wide row (a null `user_id`) is seen by everybody, which is wrong
     * for anything only the office can do — a customer's delivery request
     * would alert every driver on the roster about work none of them can
     * confirm. This addresses the feed by role instead, which is the honest
     * shape of "whoever is on the desk", since the desk is a set of accounts
     * rather than one named person.
     *
     * @param  Role[]  $roles
     * @return int how many people were told
     */
    public function pushToRoles(
        array $roles,
        string $icon,
        string $title,
        string $detail,
        Tone $tone = Tone::Info,
    ): int {
        $recipients = User::query()
            ->whereIn('role', array_map(static fn (Role $role): string => $role->value, $roles))
            ->pluck('id');

        foreach ($recipients as $userId) {
            $this->push($icon, $title, $detail, $tone, (int) $userId);
        }

        return $recipients->count();
    }

    public function markRead(NotificationItem $item): NotificationItem
    {
        $item->update(['read' => true]);

        return $item->refresh();
    }

    public function markAllRead(int $userId): int
    {
        return $this->notifications->markAllRead($userId);
    }

    public function unreadCount(int $userId): int
    {
        return $this->notifications->unreadCount($userId);
    }
}
