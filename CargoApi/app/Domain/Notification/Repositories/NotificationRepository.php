<?php

declare(strict_types=1);

namespace App\Domain\Notification\Repositories;

use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;

class NotificationRepository extends Repository
{
    protected function model(): string
    {
        return NotificationItem::class;
    }

    public function query(): Builder
    {
        return NotificationItem::query()->orderByDesc('created_at');
    }

    protected function searchable(): array
    {
        return ['title', 'detail'];
    }

    /** Mine, plus everything addressed to the whole fleet. */
    public function forUser(int $userId): Builder
    {
        return $this->query()->where(fn (Builder $q) => $q
            ->where('user_id', $userId)
            ->orWhereNull('user_id'));
    }

    public function unreadCount(int $userId): int
    {
        return $this->forUser($userId)->where('read', false)->count();
    }

    public function markAllRead(int $userId): int
    {
        return $this->forUser($userId)->where('read', false)->update(['read' => true]);
    }
}
