<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Console;

use App\Domain\Accounting\Services\AutoPostingService;
use App\Domain\Tenancy\Console\Concerns\RunsPerCompany;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Throwable;

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
