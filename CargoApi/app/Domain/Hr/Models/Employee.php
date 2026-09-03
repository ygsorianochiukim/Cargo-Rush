<?php

declare(strict_types=1);

namespace App\Domain\Hr\Models;

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'employee_no', 'first_name', 'last_name', 'middle_name',
        'position', 'position_id', 'department', 'employment_type', 'status',
        'hired_on', 'birth_date', 'contact', 'email', 'address',
        'emergency_contact', 'emergency_phone', 'base_salary_cents',
        'photo_path', 'driver_id', 'user_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'hired_on' => 'date',
            'birth_date' => 'date',
            'base_salary_cents' => 'integer',
            'employment_type' => EmploymentType::class,
            'status' => StatusValue::class,
        ];
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
