<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trucker\DTO\TruckerRegistrationData;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerVehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * An owner-operator signing themselves up.
 *
 * The third of the public registrations, and it sits between the other two.
 * A haulier's registration provisions a whole company; a shipper's writes one
 * row and belongs to nobody. This writes three rows and belongs to exactly one
 * haulier from the first second — and that difference is the decision worth
 * explaining.
 *
 * ## Why a trucker names their carrier and a shipper does not
 *
 * A shipper picks a haulier per load, on the request form, because that is a
 * choice they make weekly and with the load in front of them. A partner's
 * relationship is the other kind: it is a *standing* arrangement, with a rate,
 * a vetting and an account that carries a balance from week to week. There is
 * no coherent state in which a trucker is registered but with nobody — the
 * wallet would have no rate, the vetting would have no vetter, and the job
 * board would have no jobs.
 *
 * So the sign-up form asks, from the same public carrier directory the shipper
 * reads, and the account belongs to that haulier from the moment it exists. A
 * partner who later wants to haul for a second firm registers with that firm
 * too; two hauliers keep two opinions of a contractor exactly as they keep two
 * opinions of a customer, and the licence index is per company for that reason.
 *
 * ## What registering does not do
 *
 * It does not make them a haulier anybody can use. The row lands `pending`, and
 * the job board will not show them a single load until a human at that company
 * has read their licence and said yes — which is the whole reason the column
 * exists. Signing up buys the right to be considered, and the app says so
 * plainly rather than opening on an empty board that looks broken.
 */
class TruckerRegistrationService
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly AuthService $auth,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @return array{user: User, trucker: Trucker, token: string|null}
     */
    public function register(TruckerRegistrationData $data, Request $request): array
    {
        $carrier = $this->carrier($data);

        /**
         * Everything is written inside the carrier's tenancy, deliberately and
         * from the first statement.
         *
         * This is a public route, so no middleware has scoped anything — and
         * `Trucker`, `TruckerVehicle` and the login all carry `company_id`,
         * which the model layer stamps from whatever is in force. Left unset,
         * `BelongsToCompany` would refuse the write outright, which is the
         * correct failure and not one to arrange deliberately.
         */
        $result = $this->tenant->use($carrier, fn (): array => DB::transaction(function () use ($data, $carrier): array {
            $this->mustBeNewHere($data->licence_no);

            $user = User::create([
                ...$data->userAttributes(),
                'role' => Role::Trucker->value,
                // Named rather than left to the stamp. It is the same company
                // either way, and a login is the one row where being explicit
                // about which haulier it belongs to is worth the line.
                'company_id' => $carrier->getKey(),
            ]);

            $trucker = Trucker::create([
                ...$data->truckerAttributes(),
                'user_id' => $user->getKey(),
                // `status` is not passed, and must not be: the column defaults
                // to `pending` and a registration that could set its own
                // standing could vet itself.
            ]);

            TruckerVehicle::create([
                ...$data->vehicleAttributes(),
                'trucker_id' => $trucker->getKey(),
            ]);

            return ['user' => $user, 'trucker' => $trucker->refresh()];
        }));

        /**
         * Outside the transaction, as the shipper's registration does it and
         * for the same reason: issuing a token writes a row and starting a
         * session writes a cookie, and neither should be rolled back by a
         * failure in work that is already committed.
         */
        $token = $this->auth->establish($result['user'], $data->device_name, $request);

        $this->tellTheDesk($carrier, $result['trucker']);

        return [...$result, 'token' => $token];
    }

    /**
     * One partner per licence per haulier.
     *
     * The unique index says the same thing and is what actually guarantees it,
     * but a constraint violation reaches the client as a 500 with a stack
     * trace — and the person on the other end of it is somebody registering
     * for the first time who now believes the app is broken. This is the same
     * rule, asked first, so the answer is a sentence.
     *
     * Checked inside the carrier's tenancy, so it asks about *this* fleet's
     * books. The same licence registered with a different haulier is somebody
     * hauling for two firms, which is ordinary and is not this check's
     * business — see the column.
     *
     * A soft-deleted partner counts. Somebody the office removed and who signs
     * up again is a conversation to have with the office, not a second row that
     * silently loses the first one's wallet.
     */
    private function mustBeNewHere(string $licenceNo): void
    {
        $exists = Trucker::withTrashed()->where('licence_no', $licenceNo)->exists();

        abort_if(
            $exists,
            422,
            'That licence is already registered with this fleet. Sign in instead, or contact the office.',
        );
    }

    /**
     * Which fleet they are signing up to.
     *
     * **Nobody is asked.** A trucker registers with the platform's own fleet —
     * Cargo Rush — wherever in the country they happen to be, and that firm
     * vets them. The sign-up form used to offer the public carrier directory
     * and it was the wrong question in two ways: it asked somebody with a truck
     * to pick a haulier before they had any basis to, and it implied a choice
     * the business does not actually offer.
     *
     * Where a fleet *is* chosen is on the customer's side, per load, between
     * Cargo Rush and whichever vetted truckers are near them. A trucker's
     * relationship, by contrast, is a standing one with a rate and a running
     * balance, and there is exactly one firm holding the other end of it.
     *
     * `company_id` is still honoured when a caller sends one, and that is not
     * dead weight: `companies` is a real table with real tenancy behind it, and
     * an install running two fleets would otherwise have no way to say which.
     * The app sends nothing, so in practice this resolves the host fleet every
     * time.
     *
     * Read with the scope lifted, which is the narrow exception the carrier
     * directory already makes — `companies` has never been tenant-scoped
     * because it *is* the tenants, and a public caller has no tenancy to read
     * it from.
     */
    private function carrier(TruckerRegistrationData $data): Company
    {
        $carrier = $data->company_id !== null && $data->company_id !== ''
            ? Company::query()->find($data->company_id)
            : $this->hostFleet();

        abort_if($carrier === null, 404, 'That fleet could not be found.');
        abort_unless(
            $carrier->status->value === 'active',
            422,
            'Registrations are closed at the moment. Try again shortly.',
        );

        return $carrier;
    }

    /**
     * The fleet this install belongs to.
     *
     * The company named by `cargo.truckers.registers_with`, and the oldest on
     * the install otherwise — which is the one registration created, and the
     * same fallback the seeders and the test suite already use for "this
     * install's company". A code rather than an id, because an id is different
     * on every install and a code is the firm's handle.
     */
    private function hostFleet(): ?Company
    {
        $code = config('cargo.truckers.registers_with');

        if (is_string($code) && $code !== '') {
            return Company::query()->where('code', $code)->first();
        }

        return Company::query()->oldest()->first();
    }

    /**
     * Tell the office somebody is asking to haul for them.
     *
     * Not a courtesy. The registration lands `pending` and stays there until a
     * human acts on it, so a notification nobody sends is a partner sitting in
     * a waiting room indefinitely, watching an empty job board and concluding
     * the app is broken.
     *
     * Sent to the pair who can actually act on it — the same two a new customer
     * and a new delivery request go to.
     */
    private function tellTheDesk(Company $carrier, Trucker $trucker): void
    {
        $this->tenant->use($carrier, function () use ($trucker): void {
            $this->notifications->pushToRoles(
                roles: [Role::Administrator, Role::Dispatcher],
                icon: 'fleet',
                title: 'New trucker registered',
                detail: $trucker->name.' is waiting to be approved',
                tone: Tone::Warning,
            );
        });
    }
}
