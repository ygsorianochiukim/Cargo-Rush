<?php

declare(strict_types=1);

namespace App\Domain\Customer\Services;

use App\Domain\Billing\Repositories\InvoiceRepository;
use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\DTO\TripData;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Repositories\TripRepository;
use App\Domain\Trip\Services\TripService;
use Illuminate\Database\Eloquent\Collection;

/**
 * The customer's own view of their work and their money.
 *
 * The same arrangement the driver endpoints use, for the same reason: every
 * read here is scoped to a record resolved from the token, so there is no id
 * in a path for somebody to change into another firm's. A customer asking for
 * "my deliveries" is asking a different question from the office asking for
 * "all deliveries", and answering it through the module controller with a
 * filter would mean one forgotten `where` exposes the whole board.
 *
 * It owns no table. It composes the trip and invoice repositories and delegates
 * writing to `TripService`, so a request booked here goes through exactly the
 * same path — reference, delivery log, tariff quote, notification — as one the
 * office enters.
 */
class PortalService
{
    public function __construct(
        private readonly TripService $trips,
        private readonly TripRepository $tripRepository,
        private readonly InvoiceRepository $invoices,
    ) {}

    /**
     * File a request. Lands as `pending`, priced, with the desk told.
     *
     * The customer and the requesting account are the caller's, never the
     * payload's — see `DeliveryRequestRequest`, which stamps both from the
     * token. Checked again here, cheaply, because "the request is scoped to
     * whoever is signed in" is the whole security property of this module and
     * it should not rest on one form class remembering to do it.
     */
    public function submit(Customer $customer, TripData $data): Trip
    {
        abort_unless(
            $data->customer_id === $customer->id,
            403,
            'A delivery request can only be filed against your own account.',
        );

        return $this->trips->request($data);
    }

    /**
     * Everything this customer has asked for, newest first.
     *
     * The whole history rather than only what is outstanding: a customer
     * checking on a delivery is as likely to be looking for one that already
     * arrived as one that has not.
     *
     * @param  array<string, mixed>  $filters
     */
    public function requests(Customer $customer, array $filters = []): Collection
    {
        return $this->tripRepository->all([...$filters, 'customer_id' => $customer->id]);
    }

    /**
     * Their invoices — receivables only.
     *
     * A payable is money the business owes somebody else. It has no place in a
     * customer's portal, and filtering it out here rather than trusting the
     * caller means a query string cannot ask for it.
     */
    public function invoices(Customer $customer): Collection
    {
        return $this->invoices->query()
            ->where('customer_id', $customer->id)
            ->where('direction', InvoiceDirection::Receivable->value)
            ->get();
    }

    /**
     * The numbers the customer's home screen leads with.
     *
     * Their half of the same split the office dashboard shows: what they owe,
     * and what they have already paid. Derived from the invoices every time
     * rather than kept as a balance on the customer row, so it cannot drift
     * from Billing — the same reasoning as `Customer::outstandingCents()`,
     * which this uses.
     *
     * @return array<string, mixed>
     */
    public function summary(Customer $customer): array
    {
        $trips = $this->tripRepository->countsByStatus($customer->id);

        $count = static fn (StatusValue $status): int => (int) ($trips[$status->value] ?? 0);

        $paid = (int) $customer->invoices()
            ->where('direction', InvoiceDirection::Receivable->value)
            ->where('status', StatusValue::Paid->value)
            ->sum('amount_cents');

        return [
            'customer' => ['id' => $customer->id, 'name' => $customer->name],
            // Awaiting a decision from the desk. The number worth showing at
            // the top, because it is the one the customer is waiting on.
            'awaiting_confirmation' => $count(StatusValue::Pending),
            'scheduled' => $count(StatusValue::Scheduled) + $count(StatusValue::Assigned),
            'in_transit' => $count(StatusValue::InTransit),
            'delivered' => $count(StatusValue::Delivered),
            'pending_payment_cents' => $customer->outstandingCents(),
            'successful_payment_cents' => $paid,
            'currency' => 'PHP',
        ];
    }
}
