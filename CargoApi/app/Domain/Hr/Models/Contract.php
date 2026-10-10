<?php

declare(strict_types=1);

namespace App\Domain\Hr\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Contract extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'employee_id', 'pay_basis', 'tier', 'amount_cents', 'effective_from',
        'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'pay_basis' => PayBasis::class,
            'tier' => EmploymentType::class,
            'amount_cents' => 'integer',
            'effective_from' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeInForceOn(Builder $query, Carbon|string|null $on = null): Builder
    {
        $date = $on === null ? Carbon::now() : Carbon::parse($on);

        return $query->whereDate('effective_from', '<=', $date->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at');
    }

    public function summary(): string
    {
        $basis = $this->pay_basis ?? PayBasis::Monthly;
        $cents = (int) $this->amount_cents;

        if ($cents <= 0) {
            return 'No rate set yet.';
        }

        return sprintf(
            '₱%s %s',
            number_format($cents / 100, $cents % 100 === 0 ? 0 : 2),
            $basis->unit(),
        );
    }
}
