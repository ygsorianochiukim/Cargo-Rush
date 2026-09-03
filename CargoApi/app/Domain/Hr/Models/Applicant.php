<?php

declare(strict_types=1);

namespace App\Domain\Hr\Models;

use App\Domain\Shared\Enums\ApplicantStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somebody who has applied.
 *
 * Kept after the decision rather than deleted, and that is deliberate on both
 * outcomes. A hire keeps its application so the record shows where the employee
 * came from; a rejection is kept because "we already spoke to this person in
 * March" is the single most useful thing this list can tell an office.
 */
class Applicant extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'first_name', 'last_name', 'position_applied', 'contact', 'email',
        'address', 'source', 'applied_on', 'stage', 'photo_path',
        'resume_path', 'rating', 'notes', 'employee_id', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'applied_on' => 'date',
            'decided_at' => 'datetime',
            'rating' => 'integer',
            'stage' => ApplicantStage::class,
        ];
    }

    /** Set once the application became a hire. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Still waiting on the office.
     *
     * The nav badge counts these, so the rule lives here rather than being
     * restated as a `whereNotIn` wherever somebody needs it.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('stage', [
            ApplicantStage::Hired->value,
            ApplicantStage::Rejected->value,
        ]);
    }
}
