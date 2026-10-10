<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Models;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Database\Factories\MaintenanceJobFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaintenanceJob extends Model
{
    /** @use HasFactory<MaintenanceJobFactory> */
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'vehicle_id', 'kind', 'due_at', 'next_service_km', 'status',
        'cost_cents', 'completed_on', 'supplier_id', 'reference', 'note',
        'invoice_id',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isCosted(): bool
    {
        return $this->cost_cents !== null;
    }

    public function unpostedCents(): int
    {
        return (int) ($this->cost_cents ?? 0) - (int) $this->posted_cents;
    }
}
