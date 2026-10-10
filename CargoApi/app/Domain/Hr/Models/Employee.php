<?php

declare(strict_types=1);

namespace App\Domain\Hr\Models;

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\StoreCredit;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Employee extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'employee_no', 'first_name', 'last_name', 'middle_name',
        'position', 'position_id', 'department', 'employment_type', 'status',
        'hired_on', 'birth_date', 'contact', 'blood_type', 'religion', 'email', 'address',
        'emergency_contact', 'emergency_phone',
        'sss_enrolled', 'philhealth_enrolled', 'pagibig_enrolled',
        'photo_path', 'driver_id', 'user_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'hired_on' => 'date',
            'birth_date' => 'date',
            'employment_type' => EmploymentType::class,
            'sss_enrolled' => 'boolean',
            'philhealth_enrolled' => 'boolean',
            'pagibig_enrolled' => 'boolean',
            'status' => StatusValue::class,
        ];
    }

    /** @return array{sss: bool, philhealth: bool, pagibig: bool} */
    public function statutoryEnrolment(): array
    {
        return [
            'sss' => $this->sss_enrolled ?? false,
            'philhealth' => $this->philhealth_enrolled ?? false,
            'pagibig' => $this->pagibig_enrolled ?? false,
        ];
    }

    public function hasStatutoryExemption(): bool
    {
        return in_array(false, $this->statutoryEnrolment(), true);
    }

    public function storeCredits(): HasMany
    {
        return $this->hasMany(StoreCredit::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class)
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at');
    }

    public function contractOn(Carbon|string|null $date = null): ?Contract
    {
        $relation = $this->relationLoaded('contracts') ? $this->contracts : null;

        if ($relation !== null) {
            $on = $date === null ? Carbon::now() : Carbon::parse($date);

            return $relation->first(
                static fn (Contract $contract): bool => $contract->effective_from !== null
                    && $contract->effective_from->lessThanOrEqualTo($on->endOfDay()),
            );
        }

        return $this->contracts()->inForceOn($date)->first();
    }

    public function payBasisOn(Carbon|string|null $date = null): PayBasis
    {
        return $this->contractOn($date)?->pay_basis ?? PayBasis::Monthly;
    }

    public function amountCentsOn(Carbon|string|null $date = null): int
    {
        return (int) ($this->contractOn($date)?->amount_cents ?? 0);
    }

    public function paidPerUnitWorked(): bool
    {
        return $this->payBasisOn()->countsWork();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function jobPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applicant(): HasOne
    {
        return $this->hasOne(Applicant::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function isActive(): bool
    {
        return $this->status === StatusValue::Active;
    }

    public function scopeOnStaff(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }
}
