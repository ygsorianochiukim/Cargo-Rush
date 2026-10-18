<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Support\NumberOrder;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalEntry extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const VOID = 'void';

    public const MANUAL = 'manual';

    public const AUTO = 'auto';

    protected $fillable = [
        'reference', 'entry_date', 'category', 'memo',
        'source', 'source_type', 'source_id', 'source_rule', 'source_revision', 'status',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'category' => JournalCategory::class,
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
            'source_revision' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }

    public function isVoid(): bool
    {
        return $this->status === self::VOID;
    }

    public function isAutomatic(): bool
    {
        return $this->source === self::AUTO;
    }

    public function isLocked(): bool
    {
        return ! $this->isDraft();
    }

    public function counts(): bool
    {
        return $this->isPosted();
    }

    public function totalDebits(): int
    {
        return (int) $this->lines->sum('debit_cents');
    }

    public function totalCredits(): int
    {
        return (int) $this->lines->sum('credit_cents');
    }

    public function isBalanced(): bool
    {
        return $this->totalDebits() === $this->totalCredits();
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::POSTED);
    }

    public function scopeInJournalOrder(Builder $query): Builder
    {
        return NumberOrder::newestFirst($query, 'reference')->orderByDesc('entry_date');
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $entry->reference ??= static::nextReference();
        });
    }

    public static function nextReference(): string
    {
        $last = static::withTrashed()
            ->where('reference', 'like', 'JV-%')
            ->orderByDesc('reference')
            ->value('reference');

        $n = $last === null ? 0 : (int) substr((string) $last, 3);

        return 'JV-'.str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }
}
