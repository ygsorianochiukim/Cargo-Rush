<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\DTO\JournalEntryData;
use App\Domain\Accounting\DTO\JournalLineData;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Repositories\JournalRepository;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\Tone;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The general journal — writing transactions into the books.
 *
 * Everything that changes a journal entry goes through here, and there are
 * only four things you can do to one: write it, edit it while it is still a
 * draft, post it, and void it once posted. That list is short on purpose. A
 * ledger's value is that it is not a spreadsheet, and every extra verb is
 * another way for the history to stop matching what happened.
 *
 * ## The three rules this class exists to hold
 *
 * **An entry balances or it is not written.** Checked again here even though
 * `JournalEntryRequest` checks it first, because a validator only protects the
 * one door it stands at: a console command, a seeder or a future automatic
 * posting from an invoice reaches this method and not that form. The exception
 * it throws is deliberately not a validation error — by the time code is
 * calling this, an unbalanced entry is a bug, not a typo.
 *
 * **A posted entry is immutable.** No update, no delete, no re-post. This is
 * the whole difference between a journal and a notepad, and it is enforced on
 * the model (`isLocked()`) so it holds no matter which way in somebody comes.
 *
 * **Withdrawing is voiding, not deleting.** A void keeps the row, keeps its
 * lines, records who did it and why, and stops counting towards every balance.
 * Deleting it would remove the evidence that the office ever made the mistake,
 * which is exactly the thing an auditor is looking for.
 *
 * ## Lines are replaced, never merged
 *
 * Editing a draft replaces its lines wholesale. Merging would mean deciding
 * what a payload missing one line meant — deleted, or unchanged? — and the two
 * answers differ by a whole side of a transaction. Replacing has one meaning,
 * and the request requires the full set for the same reason.
 */
class JournalService
{
    public function __construct(
        private readonly JournalRepository $journal,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->journal->paginate($filters, $perPage);
    }

    public function find(string $id): JournalEntry
    {
        return $this->journal->findOrFail($id);
    }

    /**
     * Write a new entry, as a draft or straight into the books.
     *
     * One transaction around the head and its lines, because they are one act:
     * an entry with no sides is not a record of anything, and a failure between
     * the two writes would leave one sitting in the journal for good.
     */
    public function create(JournalEntryData $data, ?User $author = null): JournalEntry
    {
        $this->mustBalance($data);

        return DB::transaction(function () use ($data, $author): JournalEntry {
            $entry = JournalEntry::create([
                ...$data->head(),
                'status' => $data->wantsPosting() ? JournalEntry::POSTED : JournalEntry::DRAFT,
                'source' => $data->source ?? JournalEntry::MANUAL,
            ]);

            if ($author !== null) {
                $entry->created_by = $author->id;
            }

            // Stamped here rather than in `create()` above so that posting on
            // the way in and posting later leave identical rows — there is one
            // definition of what "posted" looks like, and it is below.
            if ($data->wantsPosting()) {
                $entry->posted_at = now();
                $entry->posted_by = $author?->id;
            }

            $entry->save();

            $this->writeLines($entry, $data->lines);

            if ($entry->isPosted()) {
                $this->tellTheOffice($entry->refresh());
            }

            return $entry->refresh()->load('lines.account');
        });
    }

    /**
     * Edit a draft.
     *
     * Refuses anything that has been posted, with a sentence that says what to
     * do instead. A 422 rather than a 403: the caller has every right to edit
     * journal entries, and this particular one is simply past editing.
     */
    public function update(JournalEntry $entry, JournalEntryData $data): JournalEntry
    {
        $this->mustBeOpen($entry);

        // Only when lines were sent. A PATCH that renames the memo should not
        // have to resend both sides — but one that sends lines at all sends all
        // of them, and the request enforces that.
        if ($data->lines !== []) {
            $this->mustBalance($data);
        }

        return DB::transaction(function () use ($entry, $data): JournalEntry {
            $entry->update($data->head());

            if ($data->lines !== []) {
                $entry->lines()->delete();
                $this->writeLines($entry, $data->lines);
            }

            return $entry->refresh()->load('lines.account');
        });
    }

    /**
     * Put a draft in the books.
     *
     * The balance check runs on the *stored* lines rather than on a payload,
     * because this is the last moment anything can be done about it — and an
     * entry can have been left unbalanced by an older client or a direct write
     * even if the form that made it insisted otherwise.
     */
    public function post(JournalEntry $entry, ?User $author = null): JournalEntry
    {
        $this->mustBeOpen($entry);

        $entry->load('lines');

        abort_if(
            $entry->lines->count() < 2,
            422,
            'An entry needs at least two lines before it can be posted — what was debited, and what was credited.',
        );

        abort_unless($entry->isBalanced(), 422, sprintf(
            'This entry does not balance: debits are ₱%s against credits of ₱%s. Fix the lines before posting.',
            number_format($entry->totalDebits() / 100, 2),
            number_format($entry->totalCredits() / 100, 2),
        ));

        // `forceFill`, because none of the three is fillable: who posted an
        // entry and when is the system's record of the act, not a field a
        // payload gets to set. Mass assignment would drop them silently and
        // leave a posted entry that says nobody posted it.
        $entry->forceFill([
            'status' => JournalEntry::POSTED,
            'posted_at' => now(),
            'posted_by' => $author?->id,
        ])->save();

        $this->tellTheOffice($entry);

        return $entry->refresh()->load('lines.account');
    }

    /**
     * Withdraw a posted entry.
     *
     * The row stays, its lines stay, and every balance stops counting it. A
     * reason is required because a void with no explanation is the thing
     * whoever reads the books next has to ring somebody about.
     *
     * A draft cannot be voided — it was never in the books, so there is nothing
     * to withdraw. Delete it instead, which is what `delete()` allows.
     */
    public function void(JournalEntry $entry, string $reason, ?User $author = null): JournalEntry
    {
        abort_unless(
            $entry->isPosted(),
            422,
            $entry->isVoid()
                ? 'That entry has already been voided.'
                : 'Only a posted entry can be voided. A draft can simply be deleted.',
        );

        // `forceFill` for the same reason as posting: who withdrew this and
        // why is the record of the act.
        $entry->forceFill([
            'status' => JournalEntry::VOID,
            'voided_at' => now(),
            'voided_by' => $author?->id,
            'void_reason' => $reason,
        ])->save();

        // A record superseding its own posting is bookkeeping, not news — see
        // `tellTheOffice()`.
        if ($entry->isAutomatic()) {
            return $entry->refresh()->load('lines.account');
        }

        $this->notifications->pushToRoles(
            roles: [Role::Administrator, Role::Accountant],
            icon: 'clipboard',
            title: "Journal entry {$entry->reference} voided",
            detail: trim($entry->memo.' · '.$reason),
            tone: Tone::Warning,
        );

        return $entry->refresh()->load('lines.account');
    }

    /**
     * Delete a draft.
     *
     * Its lines go with it, by the cascade on the foreign key: a side without
     * its entry is not a record of anything. A posted entry is refused —
     * voiding is how the books take something back.
     */
    public function delete(JournalEntry $entry): void
    {
        $this->mustBeOpen($entry);

        DB::transaction(function () use ($entry): void {
            $entry->lines()->delete();
            $entry->delete();
        });
    }

    /**
     * Write the sides.
     *
     * `line_no` is assigned from the position in the payload rather than taken
     * from it, so the order the accountant wrote them in survives and no two
     * lines can claim the same place. `company_id` is stamped by the model
     * layer from the tenant in force — the same path every other write takes.
     *
     * @param  JournalLineData[]  $lines
     */
    private function writeLines(JournalEntry $entry, array $lines): void
    {
        foreach (array_values($lines) as $index => $line) {
            $entry->lines()->create($line->columns($index + 1));
        }
    }

    /**
     * The two sides have to agree.
     *
     * An `abort` rather than a thrown domain exception, so the API answers 422
     * with the sentence in it — and named in pesos, because the number that
     * finds a transposed figure is the difference and not the fact of it.
     */
    private function mustBalance(JournalEntryData $data): void
    {
        abort_if(
            $data->lines === [],
            422,
            'A journal entry needs its two sides.',
        );

        abort_unless($data->isBalanced(), 422, sprintf(
            'The two sides do not agree: debits are ₱%s against credits of ₱%s.',
            number_format($data->totalDebits() / 100, 2),
            number_format($data->totalCredits() / 100, 2),
        ));
    }

    /**
     * Is this entry still somebody's draft?
     *
     * The gate on every write after the first. The message names the way
     * forward rather than just refusing: an accountant who needs to change a
     * posted entry is not doing anything wrong, they are doing it in two steps.
     */
    private function mustBeOpen(JournalEntry $entry): void
    {
        abort_if($entry->isVoid(), 422, sprintf(
            '%s has been voided. A voided entry is kept as it was — write a new one.',
            $entry->reference,
        ));

        abort_if($entry->isPosted(), 422, sprintf(
            '%s is posted and cannot be changed. Void it and write a corrected entry — '
            .'the books keep both, which is the point.',
            $entry->reference,
        ));
    }

    /**
     * Tell whoever keeps the books that something has been posted.
     *
     * Only the two roles that would act on it. A posting is not news to a
     * driver, and an opening balance is not news to anybody — it is the one
     * category that is a statement of where the books began rather than
     * something that happened today.
     *
     * Nor is an automatic posting. Every delivery, fill and payment now posts
     * itself (`AutoPostingService`), and a bell that rang for each of them
     * would ring all day and be ignored — including on the day an entry
     * somebody wrote by hand was the one worth reading.
     */
    private function tellTheOffice(JournalEntry $entry): void
    {
        if ($entry->category === JournalCategory::Opening || $entry->isAutomatic()) {
            return;
        }

        $this->notifications->pushToRoles(
            roles: [Role::Administrator, Role::Accountant],
            icon: $entry->category->icon(),
            title: "Journal entry {$entry->reference} posted",
            detail: sprintf(
                '%s · ₱%s · %s',
                $entry->category->label(),
                number_format($entry->totalDebits() / 100, 2),
                $entry->memo,
            ),
            tone: Tone::Info,
        );
    }
}
