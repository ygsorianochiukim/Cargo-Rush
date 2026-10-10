<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Database\Factories\FuelBudgetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FuelBudget extends Model
{
    /** @use HasFactory<FuelBudgetFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

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
