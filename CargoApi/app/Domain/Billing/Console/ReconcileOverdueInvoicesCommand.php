<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Billing\Services\BillingService;
use App\Domain\Shared\Support\ChangeLog;
use App\Domain\Tenancy\Console\Concerns\RunsPerCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Throwable;

class ReconcileOverdueInvoicesCommand extends Command
{
    use RunsPerCompany;

    protected $signature = 'cargo:invoices-overdue {--scheduled : Invoked by the scheduler}';

    protected $description = 'Mark invoices past their due date as overdue';

    public function handle(BillingService $billing): int
    {
        return $this->eachCompany(function () use ($billing): void {
            $run = AutomationRun::create([
                'workflow' => AutomationRun::OVERDUE_INVOICES,
                'trigger' => $this->option('scheduled') ? 'scheduled' : 'manual',
                'status' => 'running',
                'started_at' => now(),
            ]);

            try {
                $flagged = Context::scope(
                    fn (): int => $billing->reconcileOverdue(),
                    hidden: [ChangeLog::INVOICE_AUTOMATION_RUN_CONTEXT => $run->getKey()],
                );
            } catch (Throwable $exception) {
                $run->update(['status' => 'failed', 'finished_at' => now()]);

                throw $exception;
            }

            $run->update(['status' => 'completed', 'finished_at' => now()]);

            $this->info($flagged === 0 ? 'Nothing overdue.' : "Flagged {$flagged} invoice(s) overdue.");
        });
    }
}
