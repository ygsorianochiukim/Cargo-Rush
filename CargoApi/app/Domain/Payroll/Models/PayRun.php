<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Services\PayrollService;
use App\Domain\Payroll\Support\PayPeriod;
use App\Domain\Shared\Enums\PayrollScope;
use App\Domain\Shared\Support\NumberOrder;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class PayRun extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    protected $fillable = [
        'reference', 'scope', 'period_start', 'period_end', 'pay_date', 'status', 'notes', 'links_trips',
    ];

    protected $attributes = [
        'scope' => 'office',
    ];

    protected function casts(): array
    {
        return [
            'scope' => PayrollScope::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'pay_date' => 'date',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'remitted_on' => 'date',
            'links_trips' => 'boolean',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayRunLine::class)->orderBy('name');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function scope(): PayrollScope
    {
        return $this->scope ?? PayrollScope::Office;
    }

    public function isCrewRun(): bool
    {
        return $this->scope() === PayrollScope::Crew;
    }

    public function scopeOfScope(Builder $query, PayrollScope $scope): Builder
    {
        return $query->where('scope', $scope->value);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function isRemitted(): bool
    {
        return $this->remitted_on !== null;
    }

    public function owedToAgenciesCents(): int
    {
        $s = $this->statutoryCents();

        return $s['sss'] + $s['philhealth'] + $s['pagibig'] + $s['withholding_tax']
            + $this->employerContributionsCents()['total'];
    }

    public function remittanceDueOn(): Carbon
    {
        $closed = $this->period_end ?? $this->pay_date ?? $this->paid_at ?? now();

        return Carbon::parse($closed->toDateString())->addMonthNoOverflow()->endOfMonth();
    }

    public function isLocked(): bool
    {
        return ! $this->isDraft();
    }

    public function grossCents(): int
    {
        return (int) $this->lines->sum('gross_cents');
    }

    public function deductionsCents(): int
    {
        return (int) $this->lines->sum('deductions_cents');
    }

    public function netCents(): int
    {
        return (int) $this->lines->sum('net_cents');
    }

    /** @return array<string, int> */
    public function statutoryCents(): array
    {
        return [
            'sss' => (int) $this->lines->sum('sss_cents'),
            'philhealth' => (int) $this->lines->sum('philhealth_cents'),
            'pagibig' => (int) $this->lines->sum('pagibig_cents'),
            'withholding_tax' => (int) $this->lines->sum('withholding_tax_cents'),
            'other' => (int) $this->lines->sum('other_deductions_cents')
                + (int) $this->lines->sum('component_deductions_cents')
                + (int) $this->lines->sum('store_deduction_cents'),
        ];
    }

    /** @return array{sss: int, ec: int, philhealth: int, pagibig: int, total: int} */
    public function employerContributionsCents(): array
    {
        $sss = (int) $this->lines->sum('employer_sss_cents');
        $ec = (int) $this->lines->sum('employer_ec_cents');
        $philhealth = (int) $this->lines->sum('employer_philhealth_cents');
        $pagibig = (int) $this->lines->sum('employer_pagibig_cents');

        return [
            'sss' => $sss,
            'ec' => $ec,
            'philhealth' => $philhealth,
            'pagibig' => $pagibig,
            'total' => $sss + $ec + $philhealth + $pagibig,
        ];
    }

    public function isFirstCutoff(): bool
    {
        return $this->cutoff()['first'];
    }

    public function isOnlyRunOfMonth(): bool
    {
        return $this->cutoff()['only'];
    }

    public function cutoffIndex(): int
    {
        return $this->cutoff()['index'];
    }

    public function cutoffCount(): int
    {
        return $this->cutoff()['count'];
    }

    /** @return array{first: bool, only: bool, index: int, count: int} */
    private function cutoff(): array
    {
        if ($this->period_start === null || $this->period_end === null) {
            return ['first' => true, 'only' => false, 'index' => 0, 'count' => 1];
        }

        return app(PayrollService::class)->calendar()->classify($this->period_start, $this->period_end);
    }

    public function periodLabel(): string
    {
        if ($this->period_start === null || $this->period_end === null) {
            return '';
        }

        return PayPeriod::formatRange($this->period_start, $this->period_end);
    }

    public function scopeInPayrollOrder(Builder $query): Builder
    {
        return NumberOrder::newestFirst($query, 'reference')->orderByDesc('period_start');
    }

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->reference ??= static::nextReference(null, $run->scope());
        });
    }

    public static function nextReference(?int $year = null, PayrollScope $scope = PayrollScope::Office): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = "{$scope->referencePrefix()}-{$year}-";

        $last = static::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $n = $last === null ? 0 : (int) substr((string) $last, strlen($prefix));

        return $prefix.str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }

    public static function nextRemittanceNo(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = "REM-{$year}-";

        $last = static::withTrashed()
            ->where('remittance_no', 'like', $prefix.'%')
            ->orderByDesc('remittance_no')
            ->value('remittance_no');

        $n = $last === null ? 0 : (int) substr((string) $last, strlen($prefix));

        return $prefix.str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }
}
