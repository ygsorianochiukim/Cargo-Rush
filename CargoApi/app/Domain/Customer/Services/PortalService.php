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

class PortalService
{
    public function __construct(
        private readonly TripService $trips,
        private readonly TripRepository $tripRepository,
        private readonly InvoiceRepository $invoices,
        private readonly ShipperAccounts $accounts,
        private readonly CarrierDirectory $directory,
        private readonly Tenant $tenant,
        private readonly NotificationService $notifications,
    ) {}

    /**
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

    public function carrier(User $user, ?string $carrierId): Company
    {
        $linked = $this->accounts->carrierIds($user);

        if ($carrierId === null || $carrierId === '') {
            abort_if(
                $linked === [],
                422,
                'Choose the carrier you want to send this with.',
            );

            return $this->home($user);
        }

        if (! in_array($carrierId, $linked, true)) {
            $this->mustBeAbleToChoose($user);
        }

        return $this->directory->resolve($carrierId, $linked);
    }

    private function home(User $user): Company
    {
        $account = $this->accounts->home($user);

        $company = Company::query()->find($account->company_id);

        abort_if($company === null, 404, 'Your account is not attached to a carrier.');

        return $company;
    }

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

    public function accountFor(User $user, Company $carrier): Customer
    {
        return $this->accounts->open($user, $carrier);
    }

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
            $unit !== null && $unit->canCarry($trip->weight_kg, $trip->truck_category_id, $trip->truck_size_id),
            422,
            "{$trucker->name}'s truck cannot carry that load.",
        );

        $trip->update(['trucker_id' => $trucker->getKey(), 'vehicle_id' => null]);

        $this->notifications->push(
            icon: 'shipments',
            title: 'A customer asked for you',
            detail: "{$trip->reference} · {$trip->origin} → {$trip->destination}",
            tone: Tone::Warning,
            userId: $trucker->user_id,
        );
    }

    /**
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
            ->sortByDesc(fn (Trip $trip): string => (string) ($trip->scheduled_at ?? $trip->created_at))
            ->values();
    }

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

        abort(404, 'No delivery of yours with that reference.');
    }

    /** @return Collection<int, Invoice> */
    public function invoices(User $user): Collection
    {
        return $this->eachCarrier(
            $user,
            fn (Customer $account): Collection => $this->invoices->query()
                ->with([
                    'company:id,name',
                    'trip:id,reference,origin,destination,cargo,weight_kg,status,scheduled_at,created_at',
                    'allocations.payment:id,paid_on,method,reference',
                ])
                ->where('customer_id', $account->id)
                ->where('direction', InvoiceDirection::Receivable->value)
                ->get(),
        )
            ->sortByDesc(fn (Invoice $invoice): string => (string) (
                $invoice->trip?->scheduled_at ?? $invoice->issued_at ?? $invoice->created_at
            ))
            ->values();
    }

    /** @return array<string, mixed> */
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
            'customer' => ['id' => $home?->id, 'name' => $home?->name],
            ...$totals,
            'currency' => 'PHP',
            'carriers' => $carriers,
        ];
    }

    /** @return Collection<string, Customer> */
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

    /** @return array<string, int> */
    private function figuresFor(Customer $account): array
    {
        $trips = $this->tripRepository->countsByStatus($account->id);

        $count = static fn (StatusValue $status): int => (int) ($trips[$status->value] ?? 0);

        $paid = $account->paidCents();

        return [
            'awaiting_confirmation' => $count(StatusValue::Pending),
            'scheduled' => $count(StatusValue::Scheduled) + $count(StatusValue::Assigned),
            'in_transit' => $count(StatusValue::InTransit),
            'delivered' => $count(StatusValue::Delivered),
            'pending_payment_cents' => $account->outstandingCents(),
            'successful_payment_cents' => $paid,
        ];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param callable(Customer):
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
