<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Services\LogoStore;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StatementOfAccountService
{
    private const KIND_ORDER = ['invoice' => 0, 'withholding' => 1, 'payment' => 2];

    public function __construct(
        private readonly Tenant $tenant,
        private readonly LogoStore $logos,
    ) {}

    /** @return array<string, mixed> */
    public function forCustomer(
        Customer $customer,
        ?Carbon $from = null,
        ?Carbon $to = null,
        InvoiceDirection $direction = InvoiceDirection::Receivable,
    ): array {
        $to ??= Carbon::now();

        $opening = $this->openingBalance($customer, $from, $direction);

        $lines = $this->lines($customer, $from, $to, $direction)
            ->sortBy(fn (array $line): string => sprintf(
                '%s|%d|%s',
                (string) $line['date'],
                self::KIND_ORDER[$line['kind']] ?? 9,
                (string) $line['reference'],
            ))
            ->values();

        $running = $opening;
        $charges = 0;
        $credits = 0;

        $rows = $lines->map(function (array $line) use (&$running, &$charges, &$credits): array {
            $charges += $line['charge_cents'];
            $credits += $line['credit_cents'];
            $running += $line['charge_cents'] - $line['credit_cents'];

            return [...$line, 'balance_cents' => $running];
        })->all();

        $company = $this->tenant->company();

        return [
            'issuer' => [
                'name' => $company?->name,
                'tin' => $company?->tin,
                'address' => $company?->address,
                'contact_phone' => $company?->contact_phone,
                'contact_email' => $company?->contact_email,
                'logo_url' => $this->logos->url($company?->logo_path),
            ],

            'account' => [
                'id' => $customer->getKey(),
                'name' => $customer->name,
                'contact' => $customer->contact,
                'address' => $customer->address,
                'tin' => $customer->tin,
            ],

            'direction' => $direction->value,
            'title' => $direction === InvoiceDirection::Receivable
                ? 'Statement of Account'
                : 'Supplier Statement',
            'balance_label' => $direction === InvoiceDirection::Receivable
                ? 'Amount due from this account'
                : 'Amount we owe this account',

            'range' => ['from' => $from?->toDateString(), 'to' => $to->toDateString()],

            'opening_balance_cents' => $opening,
            'charges_cents' => $charges,
            'credits_cents' => $credits,
            'closing_balance_cents' => $running,
            'closing_in_words' => Money::inWords($running),

            'lines' => $rows,

            'aging' => $this->aging($customer, $to, $direction),

            'currency' => 'PHP',
        ];
    }

    private function openingBalance(
        Customer $customer,
        ?Carbon $from,
        InvoiceDirection $direction,
    ): int {
        if ($from === null) {
            return 0;
        }

        $billed = (int) Invoice::query()
            ->where('customer_id', $customer->getKey())
            ->where('direction', $direction->value)
            ->where('status', '!=', StatusValue::Cancelled->value)
            ->whereDate('issued_at', '<', $from)
            ->sum(DB::raw('amount_cents - withholding_cents'));

        $received = (int) PaymentAllocation::query()
            ->whereHas('invoice', fn ($query) => $query
                ->where('customer_id', $customer->getKey())
                ->where('direction', $direction->value))
            ->whereHas('payment', fn ($query) => $query->whereDate('paid_on', '<', $from))
            ->sum('amount_cents');

        return $billed - $received;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function lines(
        Customer $customer,
        ?Carbon $from,
        Carbon $to,
        InvoiceDirection $direction,
    ): Collection {
        $invoices = Invoice::query()
            ->with('trip:id,reference')
            ->where('customer_id', $customer->getKey())
            ->where('direction', $direction->value)
            ->where('status', '!=', StatusValue::Cancelled->value)
            ->when($from !== null, fn ($query) => $query->whereDate('issued_at', '>=', $from))
            ->whereDate('issued_at', '<=', $to)
            ->get()
            ->flatMap(fn (Invoice $invoice): array => array_values(array_filter([
                [
                    'date' => $invoice->issued_at?->toDateString(),
                    'kind' => 'invoice',
                    'reference' => $invoice->number,
                    'detail' => $invoice->trip?->reference === null
                        ? 'Invoice'
                        : 'Invoice · '.$invoice->trip->reference,
                    'invoice_id' => $invoice->getKey(),
                    'due_at' => $invoice->due_at?->toDateString(),
                    'charge_cents' => (int) $invoice->amount_cents,
                    'credit_cents' => 0,
                ],
                $invoice->withholding_cents > 0 ? [
                    'date' => $invoice->issued_at?->toDateString(),
                    'kind' => 'withholding',
                    'reference' => $invoice->number,
                    'detail' => sprintf(
                        'Withholding tax%s · kept back on %s',
                        $invoice->withholding_rate_bp > 0
                            ? ' ('.rtrim(rtrim(number_format($invoice->withholding_rate_bp / 100, 2), '0'), '.').'%)'
                            : '',
                        $invoice->number,
                    ),
                    'invoice_id' => $invoice->getKey(),
                    'due_at' => null,
                    'charge_cents' => 0,
                    'credit_cents' => (int) $invoice->withholding_cents,
                ] : null,
            ])));

        $payments = PaymentAllocation::query()
            ->with(['payment', 'invoice:id,number'])
            ->whereHas('invoice', fn ($query) => $query
                ->where('customer_id', $customer->getKey())
                ->where('direction', $direction->value))
            ->whereHas('payment', fn ($query) => $query
                ->when($from !== null, fn ($inner) => $inner->whereDate('paid_on', '>=', $from))
                ->whereDate('paid_on', '<=', $to))
            ->get()
            ->map(fn (PaymentAllocation $allocation): array => [
                'date' => $allocation->payment?->paid_on?->toDateString(),
                'kind' => 'payment',
                'reference' => $allocation->payment?->reference ?? 'Payment',
                'detail' => trim(sprintf(
                    '%s · against %s',
                    ucfirst(str_replace('_', ' ', (string) ($allocation->payment?->method ?? 'payment'))),
                    $allocation->invoice?->number ?? 'account',
                )),
                'invoice_id' => $allocation->invoice_id,
                'due_at' => null,
                'charge_cents' => 0,
                'credit_cents' => (int) $allocation->amount_cents,
            ]);

        return $invoices->concat($payments);
    }

    /** @return array<string, mixed> */
    private function aging(Customer $customer, Carbon $asOf, InvoiceDirection $direction): array
    {
        $buckets = ['current' => 0, 'days_1_30' => 0, 'days_31_60' => 0, 'days_61_90' => 0, 'over_90' => 0];

        $open = Invoice::query()
            ->withSum(
                ['allocations as paid_by_then' => static fn ($query) => $query
                    ->whereHas('payment', static fn ($payment) => $payment->whereDate('paid_on', '<=', $asOf))],
                'amount_cents',
            )
            ->where('customer_id', $customer->getKey())
            ->where('direction', $direction->value)
            ->where('status', '!=', StatusValue::Cancelled->value)
            ->whereDate('issued_at', '<=', $asOf)
            ->get();

        foreach ($open as $invoice) {
            $balance = max(0, $invoice->dueCents() - (int) ($invoice->paid_by_then ?? 0));

            if ($balance <= 0) {
                continue;
            }

            $due = $invoice->due_at;
            $late = $due === null ? 0 : (int) $due->startOfDay()->diffInDays($asOf->copy()->startOfDay(), false);

            $bucket = match (true) {
                $late <= 0 => 'current',
                $late <= 30 => 'days_1_30',
                $late <= 60 => 'days_31_60',
                $late <= 90 => 'days_61_90',
                default => 'over_90',
            };

            $buckets[$bucket] += $balance;
        }

        return [...$buckets, 'total_cents' => array_sum($buckets)];
    }
}
