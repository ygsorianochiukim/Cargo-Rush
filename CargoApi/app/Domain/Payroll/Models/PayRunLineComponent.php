<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Shared\Enums\PayComponentKind;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayRunLineComponent extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'pay_run_line_id', 'pay_component_id', 'name', 'kind', 'taxable', 'amount_cents',
        'added_by_hand',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PayComponentKind::class,
            'taxable' => 'boolean',
            'amount_cents' => 'integer',
            'added_by_hand' => 'boolean',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PayRunLine::class, 'pay_run_line_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }

    public function isEarning(): bool
    {
        return $this->kind === PayComponentKind::Earning;
    }
}
