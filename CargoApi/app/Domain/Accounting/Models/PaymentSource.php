<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Finance\Models\Expense;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Shared\Enums\PaymentSourceKind;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentSource extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = ['name', 'kind', 'provider', 'last_four', 'note', 'status'];

    protected function casts(): array
    {
        return [
            'kind' => PaymentSourceKind::class,
            'status' => StatusValue::class,
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function fuelRecords(): HasMany
    {
        return $this->hasMany(FuelRecord::class);
    }

    public function deliveryLogs(): HasMany
    {
        return $this->hasMany(DeliveryLog::class);
    }

    public function label(): string
    {
        return $this->last_four ? $this->name.' ···· '.$this->last_four : $this->name;
    }

    public function isInUse(): bool
    {
        return $this->expenses()->withTrashed()->exists()
            || $this->fuelRecords()->withTrashed()->exists()
            || $this->deliveryLogs()->withTrashed()->exists();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }
}
