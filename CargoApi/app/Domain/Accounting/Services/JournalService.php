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

class JournalService
{
    public function __construct(
        private readonly JournalRepository $journal,
        private readonly NotificationService $notifications,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->journal->paginate($filters, $perPage);
    }

    public function find(string $id): JournalEntry
    {
        return $this->journal->findOrFail($id);
    }

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

    public function update(JournalEntry $entry, JournalEntryData $data): JournalEntry
    {
        $this->mustBeOpen($entry);

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

        $entry->forceFill([
            'status' => JournalEntry::POSTED,
            'posted_at' => now(),
            'posted_by' => $author?->id,
        ])->save();

        $this->tellTheOffice($entry);

        return $entry->refresh()->load('lines.account');
    }

    public function void(JournalEntry $entry, string $reason, ?User $author = null): JournalEntry
    {
        abort_unless(
            $entry->isPosted(),
            422,
            $entry->isVoid()
                ? 'That entry has already been voided.'
                : 'Only a posted entry can be voided. A draft can simply be deleted.',
        );

        $entry->forceFill([
            'status' => JournalEntry::VOID,
            'voided_at' => now(),
            'voided_by' => $author?->id,
            'void_reason' => $reason,
        ])->save();

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

    public function delete(JournalEntry $entry): void
    {
        $this->mustBeOpen($entry);

        DB::transaction(function () use ($entry): void {
            $entry->lines()->delete();
            $entry->delete();
        });
    }

    /** @param JournalLineData[] $lines */
    private function writeLines(JournalEntry $entry, array $lines): void
    {
        foreach (array_values($lines) as $index => $line) {
            $entry->lines()->create($line->columns($index + 1));
        }
    }

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
