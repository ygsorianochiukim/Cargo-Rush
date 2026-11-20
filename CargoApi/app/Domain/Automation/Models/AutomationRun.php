<?php

declare(strict_types=1);

namespace App\Domain\Automation\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AutomationRun extends Model
{
    use BelongsToCompany, HasUlids;

    public const OVERDUE_INVOICES = 'overdue-invoices';

    public $timestamps = false;

    protected $fillable = ['workflow', 'trigger', 'status', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
