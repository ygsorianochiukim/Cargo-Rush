<?php

declare(strict_types=1);

namespace App\Domain\Notification\Controllers;

use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Notification\Resources\NotificationResource;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Notification Management — the feed, and marking it read. */
class NotificationController extends ApiController
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $page = $this->notifications->paginate($userId, $this->perPage($request));

        return $this->collection(
            NotificationResource::collection($page),
            $page,
            ['unread' => $this->notifications->unreadCount($userId)],
        );
    }

    public function read(NotificationItem $notification): JsonResponse
    {
        return $this->item(new NotificationResource($this->notifications->markRead($notification)));
    }

    public function readAll(Request $request): JsonResponse
    {
        return $this->payload(['marked' => $this->notifications->markAllRead($request->user()->id)]);
    }
}
