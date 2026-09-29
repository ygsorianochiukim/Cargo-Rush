<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\DTO\JournalEntryData;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Posting\Posting;
use App\Domain\Accounting\Posting\PostingRule;
use App\Domain\Accounting\Posting\Rules\ExpenseRule;
use App\Domain\Accounting\Posting\Rules\FuelFillRule;
use App\Domain\Accounting\Posting\Rules\InvoiceRule;
use App\Domain\Accounting\Posting\Rules\PaymentRule;
use App\Domain\Accounting\Posting\Rules\PayRunRule;
use App\Domain\Accounting\Posting\Rules\SheetDayRule;
use App\Domain\Accounting\Posting\Rules\WalletEntryRule;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Shared\Enums\BalanceSide;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trucker\Models\WalletEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every operational record, kept posted in the general journal.
 *
 * The income statement and the balance sheet are built from posted journal
 * lines, and for a long time the only thing that posted any was payroll — so
 * the statements showed ₱0 revenue for a fleet that had billed all quarter,
 * while Profitability and the Quarterly Summary showed the real figure. This
 * closes that gap: the sheet, invoices, payments, expense lines, fuel, supplier
 * bills and partners' wallets each post themselves, by a `PostingRule` per
 * kind of record, and the statements now say what the Finance screens say.
 *
 * ## One operation: sync
 *
 * `sync($record)` asks the record's rule which entries it *should* have, looks
 * at which automatic entries it *has*, and makes the second match the first:
 *
 *   the same lines, accounts and date — nothing happens;
 *   different — the old entry is voided ("Superseded by a change to …") and
 *   the new one posted, as the next revision;
 *   no longer wanted (cancelled, deleted, pending) — the old one is voided.
 *
 * So it is idempotent and safe to call as often as anything likes. That is the
 * point of it: the observers call it on every save, the backfill calls it on
 * every record, and neither has to know whether anything changed. Posted
 * entries are never edited — `JournalService` would refuse — so history reads
 * honestly: what was posted, what replaced it, and why.
 *
 * ## What it will not touch
 *
 * Anything it did not post. It reads and writes only `source = auto` entries,
 * so a manual entry and payroll's own posting are invisible to it. (That cuts
 * both ways: an accountant who also journalises the same invoices by hand gets
 * both. `cargo.accounting.auto_post.from` is where the two are divided.)
 *
 * An entry the accountant voids by hand is simply not there on the next sync,
 * and is posted again. Correct the record, not its entry.
 *
 * ## When it stays quiet
 *
 * Switched off (`cargo.accounting.auto_post.enabled`), a company with no chart
 * of accounts, or a chart missing an account a rule needs — the last with a
 * warning in the log, and the record's entries left as they were. A record
 * saving is never the thing that fails because the books could not follow it.
 */
class AutoPostingService
{
    /** @var array<class-string<Model>, class-string<PostingRule>> */
    private const RULES = [
        LedgerEntry::class => SheetDayRule::class,
        Invoice::class => InvoiceRule::class,
        Payment::class => PaymentRule::class,
        Expense::class => ExpenseRule::class,
        FuelRecord::class => FuelFillRule::class,
        WalletEntry::class => WalletEntryRule::class,
        PayRun::class => PayRunRule::class,
    ];

    public function __construct(
        private readonly JournalService $journal,
        private readonly Tenant $tenant,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('cargo.accounting.auto_post.enabled', true);
    }

    /** @return array<int, class-string<Model>> the kinds of record that post themselves */
    public static function sources(): array
    {
        return array_keys(self::RULES);
    }

    /** What the journal calls the kind of record that posted an entry. */
    public static function sourceLabel(?string $type): ?string
    {
        return match ($type) {
            LedgerEntry::class => 'Daily sheet',
            Invoice::class => 'Invoice',
            Payment::class => 'Payment',
            Expense::class => 'Expense',
            FuelRecord::class => 'Fuel fill',
            WalletEntry::class => 'Trucker wallet',
            PayRun::class => 'Payroll',
            default => null,
        };
    }

    public static function handles(Model $source): bool
    {
        return isset(self::RULES[$source::class]);
    }

    /**
     * Make the record's automatic entries match what it should post.
     *
     * @return array{posted: int, voided: int, unchanged: int, skipped: bool}
     */
    public function sync(Model $source, bool $dryRun = false, ?Carbon $from = null): array
    {
        $result = ['posted' => 0, 'voided' => 0, 'unchanged' => 0, 'skipped' => true];

        if (! $this->enabled() || ! self::handles($source)) {
            return $result;
        }

        $company = $source->getAttribute('company_id');

        // Posted into the record's own company, whichever one the code that
        // saved it had in force — the accounts and the entry are both scoped.
        if ($company !== null && $company !== $this->tenant->id()) {
            return $this->tenant->use($company, fn (): array => $this->sync($source, $dryRun, $from));
        }

        if (! Account::query()->exists()) {
            return $result;
        }

        $from ??= $this->startDate();

        // Read again rather than trusted: the instance an observer holds can
        // be a step behind (an `increment`, a relation written after it), and
        // the backfill must reach the same answer as the observer did.
        $fresh = $source->newQueryWithoutScopes()->whereKey($source->getKey())->first();

        $wanted = $fresh === null || $this->isTrashed($fresh)
            ? []
            : $this->rule($source)->postings($fresh);

        $wanted = array_values(array_filter(
            $wanted,
            static fn (Posting $p): bool => ! $p->isEmpty() && ($from === null || $p->date->toDateString() >= $from->toDateString()),
        ));

        $accounts = $this->accounts($wanted);

        if ($accounts === null) {
            Log::warning('Auto-posting skipped: the chart is missing an account a rule needs.', [
                'source' => $source::class,
                'id' => $source->getKey(),
                'codes' => array_values(array_unique(array_merge(...array_map(static fn (Posting $p): array => $p->codes(), $wanted)))),
            ]);

            return $result;
        }

        foreach ($wanted as $posting) {
            if (! $posting->isBalanced()) {
                // A rule bug, not a user error. Logged loudly and left alone
                // rather than posted half right.
                Log::error('Auto-posting skipped: a rule produced an unbalanced entry.', [
                    'source' => $source::class, 'id' => $source->getKey(), 'rule' => $posting->rule,
                ]);

                return $result;
            }
        }

        $result['skipped'] = false;
        $label = $this->rule($source)->label($fresh ?? $source);

        $apply = function () use ($source, $wanted, $accounts, $label, $from, $dryRun, &$result): void {
            $existing = $this->existing($source);
            $byRule = collect($wanted)->keyBy('rule');

            foreach ($byRule->keys()->merge($existing->keys())->unique() as $rule) {
                /** @var Posting|null $posting */
                $posting = $byRule->get($rule);
                /** @var Collection<int, JournalEntry> $current */
                $current = $existing->get($rule, collect());

                // Before `from`, and nothing wanted in its place: somebody's
                // earlier books, or history this install chose not to own.
                if ($posting === null && $from !== null) {
                    $current = $current->filter(static fn (JournalEntry $e): bool => $e->entry_date->toDateString() >= $from->toDateString());
                }

                $keep = $posting === null
                    ? null
                    : $current->first(fn (JournalEntry $e): bool => $this->fingerprint($e) === $this->wantedFingerprint($posting, $accounts));

                foreach ($current as $entry) {
                    if ($keep !== null && $entry->is($keep)) {
                        continue;
                    }

                    $result['voided']++;

                    if (! $dryRun) {
                        $this->journal->void($entry, $posting === null
                            ? "Withdrawn: {$label} no longer counts towards the books"
                            : "Superseded by a change to {$label}");
                    }
                }

                if ($posting === null) {
                    continue;
                }

                if ($keep !== null) {
                    $result['unchanged']++;

                    continue;
                }

                $result['posted']++;

                if (! $dryRun) {
                    $this->post($source, $posting, $accounts);
                }
            }
        };

        $dryRun ? $apply() : DB::transaction($apply);

        return $result;
    }

    /** The first day auto-posting owns, if the install set one. */
    public function startDate(): ?Carbon
    {
        $from = config('cargo.accounting.auto_post.from');

        return $from === null || $from === '' ? null : Carbon::parse((string) $from)->startOfDay();
    }

    private function post(Model $source, Posting $posting, array $accounts): void
    {
        $revision = (int) JournalEntry::withTrashed()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('source_rule', $posting->rule)
            ->max('source_revision') + 1;

        $this->journal->create(JournalEntryData::fromArray([
            'entry_date' => $posting->date->toDateString(),
            'category' => $posting->category->value,
            'memo' => $posting->memo,
            'status' => JournalEntry::POSTED,
            'source' => JournalEntry::AUTO,
            'source_type' => $source->getMorphClass(),
            'source_id' => (string) $source->getKey(),
            'source_rule' => $posting->rule,
            'source_revision' => $revision,
            'lines' => array_map(static fn (array $line): array => [
                'account_id' => $accounts[$line['code']],
                'side' => $line['side']->value,
                'amount_cents' => $line['cents'],
                'memo' => $line['memo'] === null ? null : mb_substr($line['memo'], 0, 250),
                'truck_id' => $line['truck_id'],
                'trip_id' => $line['trip_id'],
                'customer_id' => $line['customer_id'],
            ], $posting->lines()),
        ]));
    }

    /**
     * This record's live automatic entries, by rule.
     *
     * @return Collection<string, Collection<int, JournalEntry>>
     */
    private function existing(Model $source): Collection
    {
        return JournalEntry::query()
            ->with('lines')
            ->where('source', JournalEntry::AUTO)
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('status', JournalEntry::POSTED)
            ->orderBy('source_revision')
            ->get()
            ->groupBy('source_rule');
    }

    /**
     * Account ids by code for everything the postings name, or null when the
     * chart is missing one of them.
     *
     * @param  Posting[]  $postings
     * @return array<string, string>|null
     */
    private function accounts(array $postings): ?array
    {
        $codes = array_values(array_unique(array_merge([], ...array_map(static fn (Posting $p): array => $p->codes(), $postings))));

        if ($codes === []) {
            return [];
        }

        $found = Account::query()->whereIn('code', $codes)->pluck('id', 'code')->all();

        return count($found) === count($codes) ? $found : null;
    }

    /**
     * What makes two entries the same posting: the day, the category and every
     * line's account, side, amount and subject. Not the memo — rewording a
     * narration is not a change to the books, and reposting for it would fill
     * the journal with voids nobody needs.
     */
    private function fingerprint(JournalEntry $entry): string
    {
        $lines = $entry->lines
            ->map(static fn ($line): string => implode(':', [
                $line->account_id, (int) $line->debit_cents, (int) $line->credit_cents,
                $line->truck_id, $line->trip_id, $line->customer_id,
            ]))
            ->sort()
            ->implode('|');

        return $entry->entry_date->toDateString().'#'.$entry->category->value.'#'.$lines;
    }

    /** @param  array<string, string>  $accounts */
    private function wantedFingerprint(Posting $posting, array $accounts): string
    {
        $lines = collect($posting->lines())
            ->map(static fn (array $line): string => implode(':', [
                $accounts[$line['code']],
                $line['side'] === BalanceSide::Debit ? $line['cents'] : 0,
                $line['side'] === BalanceSide::Credit ? $line['cents'] : 0,
                $line['truck_id'], $line['trip_id'], $line['customer_id'],
            ]))
            ->sort()
            ->implode('|');

        return $posting->date->toDateString().'#'.$posting->category->value.'#'.$lines;
    }

    private function rule(Model $source): PostingRule
    {
        return app(self::RULES[$source::class]);
    }

    private function isTrashed(Model $model): bool
    {
        return method_exists($model, 'trashed') && $model->trashed();
    }
}
