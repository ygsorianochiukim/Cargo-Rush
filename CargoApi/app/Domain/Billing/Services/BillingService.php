<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\DTO\InvoiceData;
use App\Domain\Billing\DTO\TaxBreakdown;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Repositories\InvoiceRepository;
use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Support\RateBook;
use App\Domain\Trip\Models\Trip;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly TaxService $tax,
        private readonly PaymentService $payments,
        private readonly RateBook $rates,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->invoices->paginate($filters, $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Invoice>
     */
    public function all(array $filters = []): Collection
    {
        return $this->invoices->all($filters);
    }

    public function create(InvoiceData $data): Invoice
    {
        $attributes = $data->persistable();

        if (($attributes['status'] ?? null) !== StatusValue::Cancelled->value) {
            $attributes['status'] = isset($attributes['due_at']) && Carbon::parse($attributes['due_at'])->lt(today())
                ? StatusValue::Overdue->value
                : StatusValue::Pending->value;
        }

        return Invoice::create([
            ...$attributes,
            ...$this->taxFor($attributes)->columns(),
        ])->refresh();
    }

    /** @throws ValidationException */
    public function update(Invoice $invoice, InvoiceData $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data): Invoice {
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            $attributes = $data->persistable();
            $paid = (int) $invoice->allocations()->sum('amount_cents');

            $cancelling = $data->wasGiven('status') && $data->status === StatusValue::Cancelled;
            $staysCancelled = ! $data->wasGiven('status') && $invoice->status === StatusValue::Cancelled;

            if ($cancelling && $paid > 0) {
                throw ValidationException::withMessages([
                    'status' => [sprintf(
                        '%s has %s paid against it. Remove the payment first, then cancel it.',
                        $invoice->number,
                        number_format($paid / 100, 2),
                    )],
                ]);
            }

            $rechargeable = $data->wasGiven('amount_cents')
                || $data->wasGiven('customer_id')
                || $data->wasGiven('direction');

            if ($rechargeable) {
                $tax = $this->taxFor([
                    'amount_cents' => $attributes['amount_cents'] ?? $invoice->taxBaseCents(),
                    'customer_id' => $attributes['customer_id'] ?? $invoice->customer_id,
                    'direction' => $attributes['direction'] ?? $invoice->direction->value,
                ]);

                if ($tax->dueCents() < $paid) {
                    throw ValidationException::withMessages([
                        'amount_cents' => [sprintf(
                            '%s already has %s paid against it, which is more than the new amount leaves due. '
                            .'Remove or reduce the payment first.',
                            $invoice->number,
                            number_format($paid / 100, 2),
                        )],
                    ]);
                }

                $attributes = [...$attributes, ...$tax->columns()];
            }

            $attributes['status'] = $cancelling || $staysCancelled
                ? StatusValue::Cancelled->value
                : StatusValue::Pending->value;

            $invoice->update($attributes);

            return $attributes['status'] === StatusValue::Cancelled->value
                ? $invoice->refresh()
                : $this->payments->refreshInvoice($invoice->refresh());
        });
    }

    /** @param array<string, mixed> $attributes */
    private function taxFor(array $attributes): TaxBreakdown
    {
        $direction = $attributes['direction'] ?? InvoiceDirection::Receivable->value;

        return $this->tax->on(
            (int) ($attributes['amount_cents'] ?? 0),
            isset($attributes['customer_id']) ? Customer::find($attributes['customer_id']) : null,
            $direction instanceof InvoiceDirection ? $direction : InvoiceDirection::from((string) $direction),
        );
    }

    /** @throws ValidationException */
    public function delete(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            $paid = (int) $invoice->allocations()->sum('amount_cents');

            if ($paid > 0) {
                throw ValidationException::withMessages([
                    'invoice' => [sprintf(
                        '%s has %s paid against it. Remove the payment first, then delete it.',
                        $invoice->number,
                        number_format($paid / 100, 2),
                    )],
                ]);
            }

            $this->invoices->delete($invoice);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function settle(Invoice $invoice, array $attributes = [], ?int $userId = null): Invoice
    {
        if ($invoice->balanceCents() <= 0) {
            return $invoice;
        }

        $this->payments->settle($invoice, $attributes, $userId);

        return $invoice->refresh();
    }

    /** @return array<string, mixed> */
    public function aging(InvoiceDirection $direction = InvoiceDirection::Receivable): array
    {
        $buckets = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
        $byCustomer = [];

        foreach ($this->invoices->outstandingInvoices($direction) as $invoice) {
            $balance = $invoice->balanceCents();

            if ($balance <= 0) {
                continue;
            }

            $daysLate = (int) now()->startOfDay()->diffInDays($invoice->due_at, false) * -1;

            $bucket = match (true) {
                $daysLate <= 0 => 'current',
                $daysLate <= 30 => '1_30',
                $daysLate <= 60 => '31_60',
                $daysLate <= 90 => '61_90',
                default => 'over_90',
            };

            $buckets[$bucket] += $balance;

            $name = $invoice->counterparty();

            $byCustomer[$name] ??= [
                'counterparty' => $name,
                'total_cents' => 0,
                ...array_fill_keys(array_keys($buckets), 0),
            ];

            $byCustomer[$name][$bucket] += $balance;
            $byCustomer[$name]['total_cents'] += $balance;
        }

        usort($byCustomer, static fn (array $a, array $b): int => $b['total_cents'] <=> $a['total_cents']);

        return [
            'buckets' => $buckets,
            'total_cents' => array_sum($buckets),
            'by_counterparty' => array_values($byCustomer),
            'currency' => 'PHP',
        ];
    }

    public function raiseForTrip(Trip $trip, ?CarbonInterface $at = null): ?Invoice
    {
        PricingService::mustBePriced($trip);

        if ($trip->customer_id === null || $trip->price_cents <= 0) {
            return null;
        }

        $existing = Invoice::withTrashed()
            ->where('trip_id', $trip->id)
            ->where('direction', InvoiceDirection::Receivable->value)
            ->first();

        if ($existing !== null) {
            return $existing->trashed() ? null : $existing;
        }

        $issued = $at !== null ? Carbon::instance($at) : now();

        $tax = $this->tax->on($trip->price_cents, $trip->customer, InvoiceDirection::Receivable);

        try {
            return DB::transaction(fn (): Invoice => Invoice::create([
                'customer_id' => $trip->customer_id,
                'trip_id' => $trip->id,
                'issued_at' => $issued->toDateString(),
                'due_at' => $issued->copy()->addDays($this->rates->billingTermsDays())->toDateString(),
                'currency' => $trip->currency,
                'direction' => InvoiceDirection::Receivable->value,
                'status' => StatusValue::Pending->value,
                ...$tax->columns(),
            ]));
        } catch (UniqueConstraintViolationException $e) {
            return Invoice::where('trip_id', $trip->id)
                ->where('direction', InvoiceDirection::Receivable->value)
                ->first() ?? throw $e;
        }
    }

    /** @return array<string, mixed> */
    public function totals(): array
    {
        $receivable = $this->invoices->outstanding(InvoiceDirection::Receivable);
        $payable = $this->invoices->outstanding(InvoiceDirection::Payable);

        return [
            'receivable_cents' => $receivable,
            'payable_cents' => $payable,
            'net_position_cents' => $receivable - $payable,
            'collected_cents' => $this->invoices->collected(InvoiceDirection::Receivable),
            'currency' => 'PHP',
        ];
    }

    public function reconcileOverdue(): int
    {
        $count = 0;

        foreach ($this->invoices->pendingPastDue() as $invoice) {
            $invoice->update(['status' => StatusValue::Overdue->value]);
            $count++;
        }

        return $count;
    }
}
