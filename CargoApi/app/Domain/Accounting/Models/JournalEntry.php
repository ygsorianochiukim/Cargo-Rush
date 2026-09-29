<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One transaction in the general journal.
 *
 * A date, a reference, a category, a memo — and its sides on `journal_lines`.
 * There is no amount column, because a transaction does not have *an* amount:
 * it has debits and credits, they are equal, and the pair of them is what the
 * journal shows. `totalDebits()` reads the lines rather than a stored figure,
 * so an entry can never claim a total its own sides do not add up to.
 *
 * ## The three states, and why two of them are one-way
 *
 * `draft` is somebody's work in progress. Editable, deletable, and invisible to
 * every balance — an entry nobody has posted has not happened.
 *
 * `posted` is in the books. Not editable and not deletable, and that is not a
 * missing feature: a ledger that can be quietly rewritten afterwards is not a
 * record of anything. The check is here, in `isLocked()`, rather than in the
 * controller, so it holds for a console command and a future automatic posting
 * as well as for a form.
 *
 * `void` is a posted entry taken back. The row and its lines stay exactly as
 * they were and stop counting, so the history reads honestly: somebody posted
 * this, and then somebody withdrew it, and here is the reason they gave. A
 * deleted row would have made the same correction invisible.
 */
class JournalEntry extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    /** Work in progress. Editable, and counts towards nothing. */
    public const DRAFT = 'draft';

    /** In the books. Immutable from here on. */
    public const POSTED = 'posted';

    /** Posted and withdrawn. Kept, and excluded from every balance. */
    public const VOID = 'void';

    /** Somebody at a keyboard, as opposed to a document posting itself. */
    public const MANUAL = 'manual';

    /**
     * Posted by an operational record — a delivery, an invoice, a day on the
     * sheet — through `AutoPostingService`, and kept true by it: a change to
     * the record voids this and posts its replacement. Payroll keeps its own
     * `payroll` source, which predates this.
     */
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

    /**
     * The sides, in the order they were written.
     *
     * Debits first is the convention every hand-written journal follows, and
     * `line_no` is what preserves it — an entry read back in insertion order
     * would come out however the database felt like returning it.
     */
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

    /**
     * Is this entry beyond changing?
     *
     * True for anything that has ever been posted, void included. Editing a
     * void entry would be editing the record of a correction, which is the one
     * thing an audit trail must not allow.
     */
    /** Did a record post this itself, rather than somebody at a keyboard? */
    public function isAutomatic(): bool
    {
        return $this->source === self::AUTO;
    }

    public function isLocked(): bool
    {
        return ! $this->isDraft();
    }

    /** Does this entry count towards a balance? Only a posted one does. */
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

    /**
     * Do the two sides agree?
     *
     * The one question that decides whether this is a transaction at all.
     * Asked of the loaded lines, so it answers about what is actually on the
     * row rather than about what somebody sent.
     */
    public function isBalanced(): bool
    {
        return $this->totalDebits() === $this->totalCredits();
    }

    /** Only postings that count. Every balance in the system starts here. */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::POSTED);
    }

    /**
     * The journal's own order: by the day it belongs to, then by reference.
     *
     * Newest first, because a journal is read from the end — and the reference
     * as the tie-break so a day's entries come out in the order they were
     * written rather than in whatever order they are stored.
     */
    public function scopeInJournalOrder(Builder $query): Builder
    {
        return $query->orderByDesc('entry_date')->orderByDesc('reference');
    }

    /** Same rule as a trip: the reference is assigned before the insert. */
    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $entry->reference ??= static::nextReference();
        });
    }

    /**
     * The next reference in the JV-#### series.
     *
     * Per company, because the query is scoped like every other — two
     * hauliers both keep a JV-0001 and neither knows about the other.
     * `withTrashed` so a deleted draft does not free its number for reuse: a
     * reference that has been quoted once must never mean a second thing.
     */
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
