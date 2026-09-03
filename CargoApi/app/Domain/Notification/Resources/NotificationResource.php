<?php

declare(strict_types=1);

namespace App\Domain\Notification\Resources;

use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin NotificationItem
 */
class NotificationResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'icon' => $this->icon,
            'title' => $this->title,
            'detail' => $this->detail,
            // ISO, not "5 hrs ago": the API never formats, the client localizes.
            'at' => $this->iso($this->created_at),
            'tone' => $this->tone->value,
            'read' => $this->read,

            ...$this->stamps(),
        ];
    }
}
