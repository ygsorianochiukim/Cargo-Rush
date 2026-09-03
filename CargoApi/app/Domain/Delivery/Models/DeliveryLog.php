<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use Database\Factories\DeliveryLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** The closed-out record of a delivery, including proof of delivery. */
class DeliveryLog extends Model
{
    /** @use HasFactory<DeliveryLogFactory> */
    use HasFactory, HasUlids, SoftDeletes;

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

    /**
     * The proof-of-delivery reference is never typed.
     *
     * It used to be a required field on the driver's hand-off form, which
     * meant the number came from wherever the driver decided — so two runs
     * could carry the same one and a delivery could be closed against a
     * reference that named nothing. Assigning it here, the same way a trip
     * gets its `CR-` reference and an invoice its `INV-`, means the driver is
     * asked only for what they can actually see at the door: the load, the
     * photograph, and the name of whoever took it.
     */
    protected static function booted(): void
    {
        static::saving(function (self $log): void {
            // Assigned at the moment the delivery is closed, not when the row
            // is opened with the trip — an undelivered log with a POD number
            // on it would read as proof that does not exist.
            if ($log->pod_ref === null && $log->delivered_at !== null) {
                $log->pod_ref = static::nextPodRef();
            }
        });
    }

    /**
     * The next reference in the `POD-#####` series.
     *
     * `withTrashed` and a length-first sort, for the same two reasons the
     * invoice series has them: the unique-ish series must not reissue a
     * soft-deleted row's number, and a plain string sort puts `-10000` before
     * `-9999` once the run passes five digits.
     */
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

    /**
     * Where the photograph can be read from, or null when there is none.
     *
     * Derived rather than stored: the disk and its public URL are
     * configuration, and a URL written into a row would be wrong the moment
     * the install moved. The client only ever sees this.
     */
    public function podImageUrl(): ?string
    {
        if ($this->pod_image_path === null) {
            return null;
        }

        return Storage::disk((string) config('cargo.pod.disk'))->url($this->pod_image_path);
    }
}
