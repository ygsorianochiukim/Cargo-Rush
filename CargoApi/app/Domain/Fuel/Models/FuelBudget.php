<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Models;

use Database\Factories\FuelBudgetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The daily fuel allowance. Spend and projection are not columns here — they
 * are summed from `fuel_records` by the service, so the two can never disagree.
 */
class FuelBudget extends Model
{
    /** @use HasFactory<FuelBudgetFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['date', 'daily_budget_cents', 'currency', 'open_requests'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'daily_budget_cents' => 'integer',
            'open_requests' => 'integer',
        ];
    }
}
