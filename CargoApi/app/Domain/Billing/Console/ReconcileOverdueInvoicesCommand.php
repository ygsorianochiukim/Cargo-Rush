<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Services\BillingService;
use App\Domain\Tenancy\Console\Concerns\RunsPerCompany;
use Illuminate\Console\Command;

class ReconcileOverdueInvoicesCommand extends Command
{
    use RunsPerCompany;

    protected $signature = 'cargo:invoices-overdue';

    protected $description = 'Mark invoices past their due date as overdue';

    public function handle(BillingService $billing): int
    {
        return $this->eachCompany(function () use ($billing): void {
            $flagged = $billing->reconcileOverdue();

            $this->info($flagged === 0 ? 'Nothing overdue.' : "Flagged {$flagged} invoice(s) overdue.");
        });
    }
}
