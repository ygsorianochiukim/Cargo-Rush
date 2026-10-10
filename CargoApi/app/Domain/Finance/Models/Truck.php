<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Factories\TruckFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Truck extends Model
{
    /** @use HasFactory<TruckFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

    protected $fillable = ['label', 'plate', 'vehicle_id', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
