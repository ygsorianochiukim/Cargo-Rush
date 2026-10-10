<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Database\Factories\NotificationItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationItem extends Model
{
    /** @use HasFactory<NotificationItemFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

    protected $fillable = ['user_id', 'icon', 'title', 'detail', 'tone', 'read'];

    protected function casts(): array
    {
        return [
            'read' => 'boolean',
            'tone' => Tone::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
