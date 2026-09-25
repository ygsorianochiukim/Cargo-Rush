<?php

declare(strict_types=1);

namespace App\Domain\Customer\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Repositories\InvoiceRepository;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Tenancy\DTO\CarrierListing;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\CarrierDirectory;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trip\DTO\TripData;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Repositories\TripRepository;
use App\Domain\Trip\Services\TripService;
use App\Domain\Trucker\Models\Trucker;
use Illuminate\Support\Collection;

/**
 * The customer's own view of their work and their money.
 *
 * The same arrangement the driver endpoints use, for the same reason: every
 * read here is scoped to a record resolved from the token, so there is no id in
 * a path for somebody to change into another firm's. A customer asking for "my
 * deliveries" is asking a different question from the office asking for "all
 * deliveries", and answering it through the module controller with a filter
 * would mean one forgotten `where` exposes the whole board.
 *
 * It owns no table. It composes the trip and invoice repositories and delegates
 * writing to `TripService`, so a request booked here goes through exactly the
 * same path — reference, delivery log, tariff quote, notification — as one the
 * office enters.
 *
 * ## Two kinds of customer, and one of them may choose
 *
 * A customer the **office added** is that haulier's. Their deliveries, their
 * invoices and every request they file are that company's, and they are shown
 * no other carrier at all — `mustBeAbleToChoose()` refuses both the list and a
 * `carrier_id` in a payload. The relationship is what the fleet is paying for.
 *
 * A customer who **signed themselves up** arrived at the platform rather than at
 * a company. They see the hauliers near the load (`carriers()`) and file with
 * whichever they pick; that carrier gets an ordinary `customers` row and an
 * ordinary pending trip in its own books, and may not be the same one next week.
 * See `ShipperAccounts` and `ShipperRegistrationService`.
 *
 * Registering asks for none of that, so such an account starts with **no
 * carriers at all** — and that is a state to answer rather than an error to
 * report. The reads total across nothing and come back empty, `carriers()` is
 * the one thing they have to do, and a request that names a haulier opens the
 * first account. A request that names none is the one refusal: there is no
 * usual carrier to fall back on yet, and picking one for somebody is the single
 * thing this feature exists not to do.
 *
 * Which means every read here spans companies — and **none of them lifts the
 * tenant scope to do it**. The accounts are resolved first, then each carrier's
 * books are read inside that carrier's own tenancy and the answers are added up
 * here. A handful of small scoped queries beats one wide unscoped one: the
 * property that keeps two hauliers apart is never suspended, not even briefly,
 * and a bug in this file can at worst mis-total a customer's own figures.
 *
 * A customer with one carrier — every customer of a single-tenant install, and
 * every customer the office created — takes exactly the path they always did,
 * because the loop runs once.
 */
class PortalService
{
    public function __construct(
        private readonly TripService $trips,
        private readonly TripRepository $tripRepository,
        private readonly InvoiceRepository $invoices,
        private readonly ShipperAccounts $accounts,
        private readonly CarrierDirectory $directory,
        private readonly Tenant $tenant,
        // Telling a partner a customer has asked for them by name. Unused for
        // every request that does not pick one, which is most of them.
        private readonly NotificationService $notifications,
    ) {}

    /**
     * The hauliers this customer could send a load with, nearest first.
     *
     * @param  array{lat: float, lng: float}|null  $point
     * @return Collection<int, CarrierListing>
     */
    public function carriers(
        User $user,
        ?array $point = null,
        ?float $radiusKm = null,
        ?string $search = null,
    ): Collection {
        $this->mustBeAbleToChoose($user);

        return $this->directory->near(
            lat: $point['lat'] ?? null,
            lng: $point['lng'] ?? null,
            radiusKm: $radiusKm,
            search: $search,
            linkedCompanyIds: $this->accounts->carrierIds($user),
        );
    }

    /**
     * Which haulier a request is for.
     *
     * The customer's pick when they made one, and their home carrier when they
     * did not — which is what every client written before the carrier list
     * sends, and what a customer the office put on the books means by "book me
     * a pickup". A named carrier goes through the directory, so an id in a
     * payload cannot name a company that is neither offering to take work nor
     * already theirs.
     */
    public function carrier(User $user, ?string $carrierId): Company
    {
        $linked = $this->accounts->carrierIds($user);

        if ($carrierId === null || $carrierId === '') {
            // A shipper who has just registered has no usual haulier to fall
            // back on, and choosing one on their behalf is the one thing this
            // must never do. A 422 with an instruction in it, which is what the
            // request form shows against the carrier card.
            abort_if(
                $linked === [],
                422,
                'Choose the carrier you want to send this with.',
            );

            return $this->home($user);
        }

        // A carrier that is not already theirs is a choice, and an account the
        // office created does not have one to make. It cannot be pointed at
        // another company by putting an id in the payload, which is the same
        // rule the carrier list follows — and it is refused rather than quietly
        // filed with the usual carrier, because silently sending a load
        // somewhere the customer did not choose is worse than an error.
        //
        // Naming a haulier they already deal with is not a choice but a client
        // being explicit, and is answered the same way either way.
        if (! in_array($carrierId, $linked, true)) {
            $this->mustBeAbleToChoose($user);
        }

        return $this->directory->resolve($carrierId, $linked);
    }

    /**
     * The haulier whose books this login started on.
     *
     * For an office-created account this is the only company it will ever
     * transact with. For a self-registered shipper it is whoever they picked
     * when they signed up, and it is the default when a later request names
     * nobody.
     */
    private function home(User $user): Company
    {
        $account = $this->accounts->home($user);

        $company = Company::query()->find($account->company_id);

        abort_if($company === null, 404, 'Your account is not attached to a carrier.');

        return $company;
    }

    /**
     * May this account see and choose other hauliers at all?
     *
     * Only a shipper who signed themselves up. A customer the office added
     * belongs to that office: their deliveries, their invoices and their
     * requests are that company's, and showing them a list of its competitors
     * would be a strange thing for a fleet to be paying for. The refusal names
     * the carrier, so the answer reads as "you are with them" rather than as a
     * fault.
     */
    private function mustBeAbleToChoose(User $user): void
    {
        if ($user->choosesCarrier()) {
            return;
        }

        abort(403, sprintf(
            'Your account is held by %s, and your deliveries go to them. '
            .'Ask them to arrange anything they cannot carry.',
            $user->company?->name ?? 'your carrier',
        ));
    }

    /** The books this customer keeps with that haulier, opened if this is the first time. */
    public function accountFor(User $user, Company $carrier): Customer
    {
        return $this->accounts->open($user, $carrier);
    }

    /**
     * File a request. Lands as `pending`, priced, with that carrier's desk told.
     *
     * The customer and the requesting account are the caller's, never the
     * payload's — see `DeliveryRequestRequest`, which stamps both from the
     * token. Checked again here, cheaply, because "the request is scoped to
     * whoever is signed in" is the whole security property of this module and
     * it should not rest on one form class remembering to do it.
     *
     * Everything happens inside the carrier's tenancy, which is what makes the
     * request theirs rather than a row filed in the wrong company: the
     * reference comes from their series, the price from their tariff, and the
     * notification goes to their dispatchers. The relations are loaded in there
     * too — read afterwards they would resolve under the caller's own company
     * and come back empty.
     */
    public function submit(Customer $account, TripData $data, ?string $truckerId = null): Trip
    {
        abort_unless(
            $data->customer_id === $account->id,
            403,
            'A delivery request can only be filed against your own account.',
        );

        return $this->tenant->use($account->company_id, function () use ($data, $truckerId): Trip {
            $trip = $this->trips->request($data);

            if ($truckerId !== null) {
                $this->offerTo($trip, $truckerId);
            }

            return $trip->refresh()->load([
                'company:id,name',
                'customer:id,name',
                'driver:id,name',
                'helpers',
                'vehicle:id,plate',
                'trucker:id,name,phone',
            ]);
        });
    }

    /**
     * Hold a request for the partner the customer picked.
     *
     * An **offer**, not an assignment, and the difference is who gets to
     * decide. The desk assigning a run is the fleet committing a contractor it
     * has a standing arrangement with; a customer picking somebody off a list
     * is a stranger asking. So the trip stays `pending` with the partner's name
     * on it: it appears on their board alone, marked as theirs to take, and it
     * is not work until they accept it.
     *
     * Which is also why nothing here sets `booking_source`. Accepting does
     * that, and accepting an unclaimed-but-named run marks it `direct` — the
     * customer found the trucker, so the money is between the two of them and
     * the fleet takes its cut of a run it never touched.
     *
     * A partner who has gone offline, been suspended, or has no truck free is
     * refused with a sentence rather than silently left for the desk to place. A
     * customer who picked a name and got somebody else would have been better
     * off not being offered the choice.
     */
    private function offerTo(Trip $trip, string $truckerId): void
    {
        $trucker = Trucker::query()->with('vehicles')->find($truckerId);

        abort_if($trucker === null, 404, 'That trucker could not be found.');

        abort_unless(
            $trucker->canTakeWork(),
            422,
            "{$trucker->name} is not available at the moment. Pick somebody else, or leave it to the fleet.",
        );

        $unit = $trucker->activeVehicle();

        abort_unless(
            $unit !== null && $unit->canCarry($trip->weight_kg, $trip->truck_category_id),
            422,
            "{$trucker->name}'s truck cannot carry that load.",
        );

        $trip->update(['trucker_id' => $trucker->getKey()]);

        $this->notifications->push(
            icon: 'shipments',
            title: 'A customer asked for you',
            detail: "{$trip->reference} · {$trip->origin} → {$trip->destination}",
            tone: Tone::Warning,
            userId: $trucker->user_id,
        );
    }

    /**
     * Everything this customer has asked for, newest first, across every
     * haulier they use.
     *
     * The whole history rather than only what is outstanding: a customer
     * checking on a delivery is as likely to be looking for one that already
     * arrived as one that has not.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Trip>
     */
    public function requests(User $user, array $filters = []): Collection
    {
        return $this->eachCarrier(
            $user,
            fn (Customer $account): Collection => $this->tripRepository
                ->all([...$filters, 'customer_id' => $account->id]),
        )
            // Re-sorted after the merge. Each carrier's rows arrive newest
            // first, and two sorted lists laid end to end are not one sorted
            // list — which on this screen would read as last month's delivery
            // above this morning's.
            ->sortByDesc(fn (Trip $trip): string => (string) ($trip->scheduled_at ?? $trip->created_at))
            ->values();
    }

    /**
     * One of their own deliveries, whoever is hauling it.
     *
     * Resolved by hand rather than by route binding, because binding would
     * resolve it under the caller's own company and a trip with another carrier
     * would come back as a 404 the customer can plainly see on their list. Each
     * carrier's books are asked in turn, inside that carrier's tenancy, and
     * ownership is checked on the row that comes back.
     */
    public function request(User $user, string $tripId): Trip
    {
        foreach ($this->accountsFor($user) as $companyId => $account) {
            $trip = $this->tenant->use((string) $companyId, fn (): ?Trip => $this->tripRepository
                ->query()
                ->with('company:id,name')
                ->where('id', $tripId)
                ->where('customer_id', $account->id)
                ->first());

            if ($trip !== null) {
                return $trip;
            }
        }

        // A 404 rather than a 403: telling somebody a trip exists but is not
        // theirs confirms the trip exists, which is itself the leak.
        abort(404, 'No delivery of yours with that reference.');
    }

    /**
     * Their invoices — receivables only, from every haulier.
     *
     * A payable is money a business owes somebody else. It has no place in a
     * customer's portal, and filtering it out here rather than trusting the
     * caller means a query string cannot ask for it.
     *
     * @return Collection<int, Invoice>
     */
    public function invoices(User $user): Collection
    {
        return $this->eachCarrier(
            $user,
            fn (Customer $account): Collection => $this->invoices->query()
                ->with([
                    'company:id,name',
                    // The haul and the money against it, because the customer's
                    // invoice screen shows the workings rather than a total —
                    // see `PortalInvoiceResource`. Eager-loaded so a page of
                    // documents is a handful of queries and not one per row.
                    'trip:id,reference,origin,destination,cargo,weight_kg,status,scheduled_at,created_at',
                    'allocations.payment:id,paid_on,method,reference',
                ])
                ->where('customer_id', $account->id)
                ->where('direction', InvoiceDirection::Receivable->value)
                ->get(),
        )
            /**
             * Newest **requested** first.
             *
             * By the day the customer asked for the pickup, not the day the
             * office raised the document. Those are usually the same week and
             * occasionally a fortnight apart, and it is the request the customer
             * remembers — they are looking for "the Silway run", which they know
             * by when they sent it.
             */
            ->sortByDesc(fn (Invoice $invoice): string => (string) (
                $invoice->trip?->scheduled_at ?? $invoice->issued_at ?? $invoice->created_at
            ))
            ->values();
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
     * Totalled across carriers, with the per-carrier breakdown alongside it.
     * The totals are what a person wants first ("is anything of mine on the
     * road?"); the breakdown is what they need to act — an overdue invoice is
     * owed to somebody in particular.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $totals = [
            'awaiting_confirmation' => 0,
            'scheduled' => 0,
            'in_transit' => 0,
            'delivered' => 0,
            'pending_payment_cents' => 0,
            'successful_payment_cents' => 0,
        ];

        $accounts = $this->accountsFor($user);
        $companies = $this->companies($accounts);
        $carriers = [];

        foreach ($accounts as $companyId => $account) {
            $figures = $this->tenant->use(
                (string) $companyId,
                fn (): array => $this->figuresFor($account),
            );

            foreach ($totals as $key => $running) {
                $totals[$key] = $running + $figures[$key];
            }

            $carriers[] = [
                'id' => (string) $companyId,
                'name' => $companies[$companyId]->name ?? 'Unknown carrier',
                'customer_id' => $account->id,
                ...$figures,
            ];
        }

        $home = $accounts->firstWhere('id', $user->customer_id) ?? $accounts->first();

        return [
            // The firm, as the haulier who first took them on knows them. Kept
            // at the top level for the clients written before a customer could
            // have more than one carrier.
            'customer' => ['id' => $home?->id, 'name' => $home?->name],
            ...$totals,
            'currency' => 'PHP',
            // Newest relationship last, which is the order they were opened in.
            'carriers' => $carriers,
        ];
    }

    /**
     * The accounts behind this login, or a 404.
     *
     * Every "my work, my money" read goes through here, so an account somebody
     * created and forgot to attach to a firm is told so plainly — as it always
     * was. An empty list would read as a customer with no deliveries, which is
     * a different and much more confusing thing to be handed.
     *
     * With one exception, and it is the reverse case: a shipper who registered
     * and has not yet chosen a haulier genuinely *is* a customer with no
     * deliveries. Nothing is broken about that account, it has simply not asked
     * for anything yet, and a 404 on the first screen they see after signing up
     * would read as a fault. So they get the empty answer, which is the true
     * one, and the home screen shows them the way to their first request.
     *
     * `carriers()` deliberately does not go through here at all. Browsing the
     * hauliers near you is exactly what an account with none should be able to
     * do: file a request with one and the account opens itself.
     *
     * @return Collection<string, Customer>
     */
    private function accountsFor(User $user): Collection
    {
        $accounts = $this->accounts->all($user);

        abort_if(
            $accounts->isEmpty() && ! $user->awaitingCarrier(),
            404,
            'This account is not linked to a customer record.',
        );

        return $accounts;
    }

    /**
     * One carrier's figures, read inside that carrier's tenancy.
     *
     * @return array<string, int>
     */
    private function figuresFor(Customer $account): array
    {
        $trips = $this->tripRepository->countsByStatus($account->id);

        $count = static fn (StatusValue $status): int => (int) ($trips[$status->value] ?? 0);

        /**
         * Money in, and money still owed — both counted from the payments.
         *
         * Not from the invoice statuses, which is what these used to be. A
         * status is a stored opinion about a document: a receivable flagged
         * `paid` with no payment behind it counted in full as collected, and a
         * part-paid one counted as nothing owed. Both are wrong in the
         * direction that matters, because this is the pair of figures a
         * customer reads as "what have I paid" and "what do I owe".
         */
        $paid = $account->paidCents();

        return [
            // Awaiting a decision from the desk. The number worth showing at
            // the top, because it is the one the customer is waiting on.
            'awaiting_confirmation' => $count(StatusValue::Pending),
            'scheduled' => $count(StatusValue::Scheduled) + $count(StatusValue::Assigned),
            'in_transit' => $count(StatusValue::InTransit),
            'delivered' => $count(StatusValue::Delivered),
            'pending_payment_cents' => $account->outstandingCents(),
            'successful_payment_cents' => $paid,
        ];
    }

    /**
     * Run a read in each of this customer's carriers and merge the answers.
     *
     * The company is attached to every row on the way past, because a customer
     * looking at two hauliers' deliveries in one list has to be able to tell
     * whose is whose — and a relation read after the loop would resolve under
     * the caller's own company and come back null.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  callable(Customer): Collection<int, TModel>  $read
     * @return Collection<int, TModel>
     */
    private function eachCarrier(User $user, callable $read): Collection
    {
        $accounts = $this->accountsFor($user);
        $companies = $this->companies($accounts);

        $rows = new Collection;

        foreach ($accounts as $companyId => $account) {
            $found = $this->tenant->use((string) $companyId, fn (): Collection => $read($account));

            $company = $companies[$companyId] ?? null;

            if ($company !== null) {
                $found->each(static fn ($row) => $row->setRelation('company', $company));
            }

            $rows = $rows->concat($found);
        }

        return $rows;
    }

    /**
     * The carriers behind these accounts, in one query.
     *
     * @param  Collection<string, Customer>  $accounts
     * @return Collection<string, Company>
     */
    private function companies(Collection $accounts): Collection
    {
        if ($accounts->isEmpty()) {
            return new Collection;
        }

        return Company::query()
            ->whereIn('id', $accounts->keys()->all())
            ->get()
            ->keyBy('id');
    }
}
