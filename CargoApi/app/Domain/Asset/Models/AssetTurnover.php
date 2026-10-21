<?php

declare(strict_types=1);

namespace App\Domain\Asset\Models;

use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetTurnover extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'asset_id', 'from_employee_id', 'to_employee_id', 'turned_over_on',
        'condition', 'status', 'note', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'turned_over_on' => 'date',
            'status' => StatusValue::class,
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id')->withTrashed();
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id')->withTrashed();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
