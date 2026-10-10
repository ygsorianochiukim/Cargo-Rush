<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Hr\Models\Employee;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\PayComponentKind;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayRunLine extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'pay_run_id', 'employee_id', 'employee_no', 'name', 'position',
        'pay_basis', 'crew', 'days_worked', 'sheet_days', 'trips',
        'basic_cents', 'allowance_cents', 'overtime_cents',
        'adjustments_cents', 'adjustment_note',
        'component_earnings_cents', 'component_deductions_cents',
        'sss_cents', 'philhealth_cents', 'pagibig_cents',
        'employer_sss_cents', 'employer_ec_cents', 'employer_philhealth_cents', 'employer_pagibig_cents',
        'sss_enrolled', 'philhealth_enrolled', 'pagibig_enrolled',
        'withholding_tax_cents', 'other_deductions_cents', 'deduction_note',
        'store_deduction_cents',
        'gross_cents', 'deductions_cents', 'net_cents',
    ];

    protected function casts(): array
    {
        return [
            'pay_basis' => PayBasis::class,
            'crew' => 'boolean',
            'days_worked' => 'integer',
            'sheet_days' => 'integer',
            'trips' => 'integer',
            'basic_cents' => 'integer',
            'allowance_cents' => 'integer',
            'overtime_cents' => 'integer',
            'adjustments_cents' => 'integer',
            'component_earnings_cents' => 'integer',
            'component_deductions_cents' => 'integer',
            'sss_cents' => 'integer',
            'philhealth_cents' => 'integer',
            'pagibig_cents' => 'integer',
            'withholding_tax_cents' => 'integer',
            'employer_sss_cents' => 'integer',
            'employer_ec_cents' => 'integer',
            'employer_philhealth_cents' => 'integer',
            'employer_pagibig_cents' => 'integer',
            'other_deductions_cents' => 'integer',
            'store_deduction_cents' => 'integer',
            'sss_enrolled' => 'boolean',
            'philhealth_enrolled' => 'boolean',
            'pagibig_enrolled' => 'boolean',
            'gross_cents' => 'integer',
            'deductions_cents' => 'integer',
            'net_cents' => 'integer',
        ];
    }

    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(PayRunLineComponent::class, 'pay_run_line_id');
    }

    public function tripLinks(): HasMany
    {
        return $this->hasMany(PayRunLineTrip::class, 'pay_run_line_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function computedGrossCents(): int
    {
        return $this->basic_cents
            + $this->allowance_cents
            + $this->overtime_cents
            + $this->adjustments_cents
            + $this->component_earnings_cents;
    }

    public function computedDeductionsCents(): int
    {
        return $this->sss_cents
            + $this->philhealth_cents
            + $this->pagibig_cents
            + $this->withholding_tax_cents
            + $this->other_deductions_cents
            + $this->component_deductions_cents
            + $this->store_deduction_cents;
    }

    public function zeroExplanation(): ?string
    {
        if ($this->gross_cents > 0) {
            return null;
        }

        $basis = $this->pay_basis ?? PayBasis::Monthly;

        if (! $basis->countsWork()) {
            return 'No salary is set on this person’s contract.';
        }

        if ($basis === PayBasis::PerTrip) {
            return $this->trips === 0
                ? 'No hauls were delivered by this person between these two dates. Per-trip pay is '
                    .'the contract rate times the trips delivered in the period, so a run they were '
                    .'not out on pays nothing.'
                : 'The period has '.$this->trips.' delivered trip(s) for this person, but their '
                    .'contract has no trip rate on it. Write them a contract with a rate and work '
                    .'the run out again.';
        }

        if ($this->sheet_days === 0) {
            return 'No truck-sheet rows name this person between these two dates. Daily pay is '
                .'counted from the daily sheet, so a run they were not written onto pays nothing. '
                .'Put them on the day’s row as driver or helper, then work the run out again.';
        }

        return 'The sheet has '.$this->sheet_days.' day(s) for this person, but their daily rate is '
            .'zero. Write them a contract with a rate and work the run out again.';
    }

    public function employerContributionsCents(): int
    {
        return (int) $this->employer_sss_cents
            + (int) $this->employer_ec_cents
            + (int) $this->employer_philhealth_cents
            + (int) $this->employer_pagibig_cents;
    }

    public function computedNetCents(): int
    {
        return $this->computedGrossCents() - $this->computedDeductionsCents();
    }

    public function isConsistent(): bool
    {
        return $this->gross_cents === $this->computedGrossCents()
            && $this->deductions_cents === $this->computedDeductionsCents()
            && $this->net_cents === $this->computedNetCents();
    }

    public function componentTotalsAgree(): bool
    {
        $rows = $this->components;

        $earnings = (int) $rows->where('kind', PayComponentKind::Earning)->sum('amount_cents');
        $deductions = (int) $rows->where('kind', PayComponentKind::Deduction)->sum('amount_cents');

        return $this->component_earnings_cents === $earnings
            && $this->component_deductions_cents === $deductions;
    }
}
