<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Services\BillingService;
use Illuminate\Console\Command;

/**
 * Move pending invoices past their due date to `overdue`.
 *
 * The exact counterpart of `cargo:trips-overdue`, and it had the same gap:
 * `BillingService::reconcileOverdue()` was written to be run on a schedule and
 * nothing ran it, so the manual's promise that "anything still pending past its
 * due date becomes Overdue on its own" was only true if somebody edited the
 * row. This is the caller that makes it true — and the Dashboard's overdue
 * figure depends on it, because a receivable that is late in fact and `pending`
 * in the table is money nobody is chasing.
 */
class ReconcileOverdueInvoicesCommand extends Command
{
    protected $signature = 'cargo:invoices-overdue';

    protected $description = 'Mark invoices past their due date as overdue';

    public function handle(BillingService $billing): int
    {
        $flagged = $billing->reconcileOverdue();

        $this->info($flagged === 0 ? 'Nothing overdue.' : "Flagged {$flagged} invoice(s) overdue.");

        return self::SUCCESS;
    }
}
