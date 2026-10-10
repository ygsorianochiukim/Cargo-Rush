<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use Database\Factories\DeliveryLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class DeliveryLog extends Model
{
    /** @use HasFactory<DeliveryLogFactory> */
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'trip_id', 'delivered_at', 'pod_ref', 'pod_image_path', 'receiver_name', 'status',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'status' => StatusValue::class,
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $log): void {
            if ($log->pod_ref === null && $log->delivered_at !== null) {
                $log->pod_ref = static::nextPodRef();
            }
        });
    }

    public static function nextPodRef(): string
    {
        $last = static::withTrashed()
            ->where('pod_ref', 'like', 'POD-%')
            ->orderByRaw('LENGTH(pod_ref) DESC')
            ->orderByDesc('pod_ref')
            ->value('pod_ref');

        $next = $last === null ? 1 : (int) substr((string) $last, 4) + 1;

        return 'POD-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    public function podImageUrl(): ?string
    {
        if ($this->pod_image_path === null) {
            return null;
        }

        return Storage::disk((string) config('cargo.pod.disk'))->url($this->pod_image_path);
    }
}
