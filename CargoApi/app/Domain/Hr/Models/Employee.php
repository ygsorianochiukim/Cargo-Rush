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

/**
 * A person on the payroll.
 *
 * The HR record, which is not the same thing as either of the two records this
 * system already had for people. `users` is a login and `drivers` is an
 * operational history; an employee is the human both of those describe, and
 * plenty of employees have neither — nobody at the front desk drives, and a
 * mechanic may never sign in.
 */
class Employee extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'employee_no', 'first_name', 'last_name', 'middle_name',
        'position', 'position_id', 'department', 'employment_type', 'status',
        'hired_on', 'birth_date', 'contact', 'email', 'address',
        'emergency_contact', 'emergency_phone',
        'sss_enrolled', 'philhealth_enrolled', 'pagibig_enrolled',
        'store_deduction_cap_cents',
        'photo_path', 'driver_id', 'user_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'hired_on' => 'date',
            'birth_date' => 'date',
            /**
             * The stage somebody is engaged at, which is also the column of the
             * position's rate card their pay was taken from when they were
             * hired. Moving between stages does not move their pay on its own —
             * that is a new contract, written deliberately.
             */
            'employment_type' => EmploymentType::class,
            /**
             * Which agencies this person is registered with.
             *
             * True for everybody by default, which is what the system assumed
             * before these existed. Off is for the cases a fleet actually has:
             * somebody not yet registered, a casual hand taken on for the
             * season, a person already contributing through another employer.
             */
            'sss_enrolled' => 'boolean',
            'philhealth_enrolled' => 'boolean',
            'pagibig_enrolled' => 'boolean',
            'store_deduction_cap_cents' => 'integer',
            'status' => StatusValue::class,
        ];
    }

    /**
     * The three switches, in the shape `StatutoryDeductions` reads.
     *
     * One method rather than three property reads at the call site, so the
     * defaulting — absent means enrolled — is written down once. A payslip that
     * decided this for itself would be a second answer to a question the
     * employee record already answers.
     *
     * @return array{sss: bool, philhealth: bool, pagibig: bool}
     */
    public function statutoryEnrolment(): array
    {
        return [
            'sss' => $this->sss_enrolled ?? true,
            'philhealth' => $this->philhealth_enrolled ?? true,
            'pagibig' => $this->pagibig_enrolled ?? true,
        ];
    }

    /** Is any statutory contribution switched off for this person? */
    public function hasStatutoryExemption(): bool
    {
        return in_array(false, $this->statutoryEnrolment(), true);
    }

    /** The store tab — the mini-mart *pautang* this person owes against. */
    public function storeCredits(): HasMany
    {
        return $this->hasMany(StoreCredit::class);
    }

    /**
     * Every agreement this person has been on, newest first.
     *
     * A history rather than a figure. Pay used to live in three columns here,
     * which meant a rise destroyed the record of what it replaced — and the
     * first question anybody asks about pay is what it was before.
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class)
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at');
    }

    /**
     * The agreement paying them on a given day — today, unless asked otherwise.
     *
     * The date matters and is not decoration: a run rebuilt for last fortnight
     * has to find last fortnight's figure, and a rise dated the first of next
     * month has to sit there not paying until it arrives.
     *
     * Null for somebody with no contract at all, which is a real state — a
     * record created before anybody said what it pays — and one that keeps them
     * off a pay run rather than putting a ₱0.00 payslip on it.
     */
    public function contractOn(Carbon|string|null $date = null): ?Contract
    {
        $relation = $this->relationLoaded('contracts') ? $this->contracts : null;

        if ($relation !== null) {
            $on = $date === null ? Carbon::now() : Carbon::parse($date);

            // Already in memory and already ordered newest first, so the first
            // row that has started is the one in force. Filtering here rather
            // than querying again is what keeps a 90-strong pay run to one
            // query for the whole table.
            return $relation->first(
                static fn (Contract $contract): bool => $contract->effective_from !== null
                    && $contract->effective_from->lessThanOrEqualTo($on->endOfDay()),
            );
        }

        return $this->contracts()->inForceOn($date)->first();
    }

    /** How this person is paid on a given day. Monthly where nothing says. */
    public function payBasisOn(Carbon|string|null $date = null): PayBasis
    {
        return $this->contractOn($date)?->pay_basis ?? PayBasis::Monthly;
    }

    /** The figure in force, in centavos. Zero where no contract says. */
    public function amountCentsOn(Carbon|string|null $date = null): int
    {
        return (int) ($this->contractOn($date)?->amount_cents ?? 0);
    }

    /**
     * Is this person's pay multiplied by work done in the period?
     *
     * True on a daily or per-trip basis, and it is the question that decides
     * whether they appear on a pay run at all — a firm that settles that money
     * in cash against the sheet keeps them off it. See
     * `PayrollService::payable()`.
     */
    public function paidPerUnitWorked(): bool
    {
        return $this->payBasisOn()->countsWork();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * The job title from the managed list, where one was chosen.
     *
     * Named `jobPosition` because `position` is already the free-text column
     * beside it — every employee on the roster before this list existed has a
     * typed title and no row to point at, and forcing one would mean guessing
     * which of them meant the same thing.
     */
    public function jobPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The application they were hired from, where there was one. */
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

    /** Staff still with the company. */
    public function scopeOnStaff(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Active->value);
    }
}
