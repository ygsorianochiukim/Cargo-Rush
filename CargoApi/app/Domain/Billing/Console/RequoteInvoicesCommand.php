<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Services\TaxService;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VatTreatment;
use App\Domain\Tenancy\Console\Concerns\RunsPerCompany;
use Illuminate\Console\Command;

class RequoteInvoicesCommand extends Command
{
    use RunsPerCompany;

    protected $signature = 'cargo:invoices-requote {--dry-run : Report what would change, and change nothing}';

    protected $description = 'Re-quote invoices left double-taxed, and clear a paid status with no payment behind it';

    public function handle(TaxService $tax): int
    {
        $pretend = (bool) $this->option('dry-run');

        if ($pretend) {
            $this->warn('Dry run: nothing will be written.');
        }

        return $this->eachCompany(function () use ($tax, $pretend): void {
            $requoted = 0;
            $unflagged = 0;
            $skipped = [];

            $invoices = Invoice::query()
                ->with(['trip:id,reference,price_cents', 'customer'])
                ->withSum('allocations', 'amount_cents')
                ->where('direction', InvoiceDirection::Receivable->value)
                ->orderBy('number')
                ->get();

            foreach ($invoices as $invoice) {
                if ($invoice->status === StatusValue::Cancelled) {
                    continue;
                }

                $paid = $invoice->paidCents();

                if ($paid === 0 && in_array($invoice->status, [StatusValue::Paid, StatusValue::Partial], true)) {
                    $this->line(sprintf(
                        '  %s · %s → %s (no payment against it)',
                        $invoice->number,
                        $invoice->status->value,
                        $invoice->statusFromPayments()->value,
                    ));

                    if (! $pretend) {
                        $invoice->forceFill([
                            'status' => $invoice->statusFromPayments()->value,
                            'paid_at' => null,
                        ])->save();
                    }

                    $unflagged++;
                }

                $trip = $invoice->trip;

                if ($trip === null || $trip->price_cents === null || $trip->price_cents <= 0) {
                    $skipped[] = sprintf('%s (raised by hand — check it yourself)', $invoice->number);

                    continue;
                }

                if ($paid > 0) {
                    $skipped[] = sprintf('%s (money against it — left alone)', $invoice->number);

                    continue;
                }

                $correct = $tax->atRates(
                    $trip->price_cents,
                    (int) $invoice->vat_rate_bp,
                    (int) $invoice->withholding_rate_bp,
                    $invoice->vat_treatment ?? VatTreatment::Vatable,
                );
                $columns = $correct->columns();

                if ((int) $columns['amount_cents'] === (int) $invoice->amount_cents
                    && (int) $columns['net_amount_cents'] === (int) $invoice->net_amount_cents) {
                    continue;
                }

                $this->line(sprintf(
                    '  %s · %s: %s → %s (net %s → %s)',
                    $invoice->number,
                    $trip->reference,
                    $this->pesos((int) $invoice->amount_cents),
                    $this->pesos((int) $columns['amount_cents']),
                    $this->pesos((int) $invoice->net_amount_cents),
                    $this->pesos((int) $columns['net_amount_cents']),
                ));

                if (! $pretend) {
                    $invoice->forceFill($columns)->save();
                }

                $requoted++;
            }

            $this->info(sprintf(
                '%s %d invoice(s), cleared %d wrongly-paid status(es).',
                $pretend ? 'Would re-quote' : 'Re-quoted',
                $requoted,
                $unflagged,
            ));

            foreach ($skipped as $note) {
                $this->comment("  skipped: {$note}");
            }
        });
    }

    private function pesos(int $cents): string
    {
        return '₱'.number_format($cents / 100, 2);
    }
}
