<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Console;

use App\Domain\Accounting\Services\AutoPostingService;
use App\Domain\Tenancy\Console\Concerns\RunsPerCompany;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Post every record's history to the journal.
 *
 * Auto-posting follows records as they are saved; everything saved before it
 * existed — every delivery, invoice and fill since the install began — is in no
 * journal. This walks each kind of record and runs the same `sync` the
 * observers run, so the result is exactly what live posting would have
 * produced, and a second run finds nothing to do.
 *
 * ## The one way to get this wrong
 *
 * An accountant who has been journalising the same months **by hand** already
 * has those invoices and that revenue in the books, and auto-posting cannot
 * tell — it never reads a manual entry. Backfilling over them counts it all
 * twice. `--from` exists for that: set it to the day after the last period
 * somebody posted by hand, and only records dated from then on are posted.
 * (The standing equivalent for live posting is `ACCOUNTING_AUTO_POST_FROM`.)
 *
 *     php artisan cargo:accounting-backfill --dry-run
 *     php artisan cargo:accounting-backfill --from=2026-07-01
 */
class BackfillAutoPostingsCommand extends Command
{
    use RunsPerCompany;

    protected $signature = 'cargo:accounting-backfill
        {--from= : Only post records dated on or after this day (YYYY-MM-DD)}
        {--dry-run : Report what would be posted or voided, and write nothing}';

    protected $description = 'Post the history of every delivery, invoice, payment, expense, fill and wallet row to the journal. '
        .'Idempotent. Entries the accountant already posted by hand for the same period will double up — use --from.';

    public function handle(AutoPostingService $posting): int
    {
        if (! $posting->enabled()) {
            $this->warn('Auto-posting is switched off (ACCOUNTING_AUTO_POST). Nothing to do.');

            return self::SUCCESS;
        }

        $from = $this->option('from') !== null ? Carbon::parse((string) $this->option('from'))->startOfDay() : $posting->startDate();
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run: nothing will be written.');
        }

        if ($from !== null) {
            $this->line("Posting records dated from {$from->toDateString()}.");
        }

        return $this->eachCompany(function () use ($posting, $from, $dryRun): void {
            $totals = ['posted' => 0, 'voided' => 0, 'unchanged' => 0, 'failed' => 0];

            foreach (AutoPostingService::sources() as $class) {
                // Oldest first, so the journal's references run in the order
                // things happened. Trashed rows too: one that posted before it
                // was deleted still has an entry to withdraw.
                $query = in_array(SoftDeletes::class, class_uses_recursive($class), true) ? $class::withTrashed() : $class::query();

                $query->chunkById(200, function ($records) use ($posting, $from, $dryRun, &$totals): void {
                    foreach ($records as $record) {
                        try {
                            $result = $posting->sync($record, $dryRun, $from);
                        } catch (Throwable $e) {
                            $totals['failed']++;
                            $this->error(sprintf('  %s %s: %s', class_basename($record), $record->getKey(), $e->getMessage()));

                            continue;
                        }

                        $totals['posted'] += $result['posted'];
                        $totals['voided'] += $result['voided'];
                        $totals['unchanged'] += $result['unchanged'];
                    }
                });
            }

            $this->info(sprintf(
                '  %s %d, voided %d, already right %d%s.',
                $dryRun ? 'Would post' : 'Posted',
                $totals['posted'],
                $totals['voided'],
                $totals['unchanged'],
                $totals['failed'] > 0 ? ", failed {$totals['failed']}" : '',
            ));
        });
    }
}
