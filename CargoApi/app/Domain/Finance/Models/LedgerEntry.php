<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'truck_id', 'trip_id', 'customer_id', 'driver_id', 'date', 'trip_income_cents', 'fuel_cents',
        'driver_salary_cents', 'helper_salary_cents', 'maintenance_cents',
        'allowance_cents', 'owner_share_cents', 'route', 'remarks', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'trip_income_cents' => 'integer',
            'fuel_cents' => 'integer',
            'driver_salary_cents' => 'integer',
            'helper_salary_cents' => 'integer',
            'maintenance_cents' => 'integer',
            'allowance_cents' => 'integer',
            'owner_share_cents' => 'integer',
        ];
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function helpers(): HasMany
    {
        return $this->hasMany(LedgerEntryHelper::class)->orderBy('position');
    }

    public function totalExpensesCents(): int
    {
        return $this->fuel_cents
            + $this->driver_salary_cents
            + $this->helper_salary_cents
            + $this->maintenance_cents
            + $this->allowance_cents
            + $this->owner_share_cents;
    }

    public function netIncomeCents(): int
    {
        return $this->trip_income_cents - $this->totalExpensesCents();
    }
}
