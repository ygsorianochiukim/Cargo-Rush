<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Shared\Enums\JournalCategory;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * What one kind of record puts in the books.
 *
 * A rule is a pure description: given the record as it stands now, which
 * entries *should* exist. It never writes, never reads what is already posted
 * and never decides whether anything changed — that is `AutoPostingService`,
 * once, for every rule. Keeping the two apart is what makes re-syncing safe: a
 * rule that returns the same postings twice has changed nothing, whoever calls
 * it and however often.
 *
 * Return an empty list when the record no longer counts — cancelled, pending,
 * a payment still in flight. The service voids whatever it had posted. (A
 * soft-deleted record never reaches a rule; the service answers for it.)
 *
 * **The dates are the Finance screens' dates.** Each rule mirrors, figure for
 * figure and by the same date field, what `FinanceService::periodRollup()`
 * counts from that record — the reconciliation test pins the two together. A
 * rule that disagreed with the screens would make two sets of books.
 */
abstract class PostingRule
{
    /** @return Posting[] */
    abstract public function postings(Model $source): array;

    /** How a void reason names the record: "invoice INV-2026-0001". */
    abstract public function label(Model $source): string;

    /** An account from `cargo.accounting.auto_post.accounts`, by its key. */
    protected function code(string $key): string
    {
        return (string) config("cargo.accounting.auto_post.accounts.{$key}");
    }

    protected function posting(string $rule, CarbonInterface $date, JournalCategory $category, string $memo): Posting
    {
        return new Posting($rule, $date, $category, mb_substr($memo, 0, 250));
    }
}
