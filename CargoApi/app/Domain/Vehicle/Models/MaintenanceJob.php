<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Models;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Database\Factories\MaintenanceJobFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An assigned service job. The driver app lists these under Inspect. */
class MaintenanceJob extends Model
{
    /** @use HasFactory<MaintenanceJobFactory> */
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'vehicle_id', 'kind', 'due_at', 'next_service_km', 'status',
        // What it came to, when it was done and who did it. All three are
        // null while the job is only booked, which is most of its life.
        'cost_cents', 'completed_on', 'supplier_id', 'reference', 'note',
        // Written by `MaintenanceService` and by nothing else: it is the
        // running total already pushed onto the daily sheet, and a payload
        // that could set it could make the sheet disagree with the job.
        'posted_cents',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'date',
            'completed_on' => 'date',
            'next_service_km' => 'integer',
            'cost_cents' => 'integer',
            'posted_cents' => 'integer',
            'status' => StatusValue::class,
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** The garage or parts shop that did it. */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Has this job been costed?
     *
     * Null is "not costed yet", which is a different fact from zero — zero is
     * a warranty job somebody was not charged for, and both are real answers.
     */
    public function isCosted(): bool
    {
        return $this->cost_cents !== null;
    }

    /** What is still to be pushed onto the daily sheet. */
    public function unpostedCents(): int
    {
        return (int) ($this->cost_cents ?? 0) - (int) $this->posted_cents;
    }
}
