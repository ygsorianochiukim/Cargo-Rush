<?php

declare(strict_types=1);

namespace App\Domain\Asset\Models;

use App\Domain\Hr\Models\Employee;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    public const CONDITIONS = ['good', 'fair', 'damaged'];

    public const RETURN_STATUSES = ['available', 'maintenance', 'inactive'];

    protected $fillable = [
        'category_id', 'tag', 'name', 'serial_no', 'purchased_on', 'cost_cents', 'supplier_id',
        'custodian_id', 'assigned_on', 'location', 'condition', 'status', 'note',
    ];

    protected function casts(): array
    {
        return [
            'purchased_on' => 'date',
            'assigned_on' => 'date',
            'cost_cents' => 'integer',
            'status' => StatusValue::class,
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'custodian_id')->withTrashed();
    }

    public function turnovers(): HasMany
    {
        return $this->hasMany(AssetTurnover::class);
    }
}
