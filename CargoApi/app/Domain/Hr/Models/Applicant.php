<?php

declare(strict_types=1);

namespace App\Domain\Hr\Models;

use App\Domain\Shared\Enums\ApplicantStage;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Applicant extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('stage', [
            ApplicantStage::Hired->value,
            ApplicantStage::Rejected->value,
        ]);
    }
}
