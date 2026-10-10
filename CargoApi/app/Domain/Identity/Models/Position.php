<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Hr\Models\Employee;
use App\Domain\Payroll\Support\PayrollCalendar;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'key', 'name', 'description', 'drives', 'position', 'status', 'pay_basis',
        'trainee_amount_cents', 'probationary_amount_cents', 'regular_amount_cents',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'status' => StatusValue::class,
            'drives' => 'boolean',
            'pay_basis' => PayBasis::class,
            'trainee_amount_cents' => 'integer',
            'probationary_amount_cents' => 'integer',
            'regular_amount_cents' => 'integer',
        ];
    }

    public function amountFor(EmploymentType $type): int
    {
        return (int) match ($type->tier()) {
            EmploymentType::Trainee => $this->trainee_amount_cents,
            EmploymentType::Probationary => $this->probationary_amount_cents,
            default => $this->regular_amount_cents,
        };
    }

    /** @return array{pay_basis: string, amount_cents: int, tier: string}|null */
    public function startingPay(EmploymentType $type): ?array
    {
        $amount = $this->amountFor($type);

        if ($amount <= 0) {
            return null;
        }

        return [
            'pay_basis' => ($this->pay_basis ?? PayBasis::Monthly)->value,
            'amount_cents' => $amount,
            'tier' => $type->tier()->value,
        ];
    }

    public function hasRateCard(): bool
    {
        return $this->trainee_amount_cents > 0
            || $this->probationary_amount_cents > 0
            || $this->regular_amount_cents > 0;
    }

    /** @return array<string, int> */
    public function rateCard(): array
    {
        $card = [];

        foreach (EmploymentType::tiers() as $tier) {
            $card[$tier->value] = $this->amountFor($tier);
        }

        return $card;
    }

    public function paySummary(): string
    {
        $basis = $this->pay_basis ?? PayBasis::Monthly;
        $regular = (int) $this->regular_amount_cents;

        if ($regular <= 0) {
            return 'No rate set yet — the figure is entered on each hire.';
        }

        if ($basis === PayBasis::PerTrip) {
            return sprintf(
                'Each payslip pays %s for every haul delivered in that cutoff.',
                $this->pesos($regular),
            );
        }

        if ($basis === PayBasis::Daily) {
            return sprintf(
                'Each payslip pays %s for every day worked in that cutoff.',
                $this->pesos($regular),
            );
        }

        $runs = PayrollCalendar::for(app(Tenant::class)->company())->runsPerMonth();

        if ($runs === 1) {
            return sprintf('%s a month, paid on one payslip.', $this->pesos($regular));
        }

        $each = intdiv($regular, $runs);

        return sprintf(
            '%s a month — about %s on each of %d payslips.',
            $this->pesos($regular),
            $this->pesos($each),
            $runs,
        );
    }

    private function pesos(int $cents): string
    {
        return '₱'.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'position_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }
}
