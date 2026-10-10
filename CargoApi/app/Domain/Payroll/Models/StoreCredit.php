<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StoreCreditKind;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreCredit extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'employee_id', 'kind', 'amount_cents', 'description',
        'outlet', 'charged_on', 'pay_run_line_id', 'recorded_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'kind' => StoreCreditKind::class,
            'amount_cents' => 'integer',
            'charged_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payRunLine(): BelongsTo
    {
        return $this->belongsTo(PayRunLine::class, 'pay_run_line_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function signedCents(): int
    {
        return $this->kind->sign() * $this->amount_cents;
    }

    public function isCharge(): bool
    {
        return $this->kind === StoreCreditKind::Charge;
    }

    public function isFromPayroll(): bool
    {
        return $this->pay_run_line_id !== null;
    }

    public function scopeUpTo(Builder $query, string $date): Builder
    {
        return $query->whereDate('charged_on', '<=', $date);
    }

    public function scopeForEmployee(Builder $query, string $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeInLedgerOrder(Builder $query): Builder
    {
        return $query->orderByDesc('charged_on')->orderByDesc('created_at')->orderByDesc('id');
    }
}
