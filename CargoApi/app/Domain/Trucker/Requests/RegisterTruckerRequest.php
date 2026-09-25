<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Trucker\DTO\TruckerRegistrationData;
use Illuminate\Validation\Rules\Password;

/**
 * An owner-operator signing themselves up — the third public write.
 *
 * The other two are a haulier registering and a shipper registering, and this
 * asks for more than either. That is proportionate rather than unfriendly: a
 * bad shipper registration wastes a desk's afternoon, and a bad trucker
 * registration is a stranger driving away with somebody's cargo.
 *
 * Four things, and each is here because something downstream cannot work
 * without it:
 *
 *   **The carrier.** A partner's relationship is a standing one — a rate, a
 *   vetting, a running balance — and there is no coherent state in which those
 *   exist with nobody. A shipper genuinely can pick per load; a partner cannot.
 *   See `TruckerRegistrationService` for the longer form of this argument.
 *
 *   **The licence.** It is what the haulier is relying on when it hands them a
 *   load, and the one thing a human at the desk actually reads before
 *   approving.
 *
 *   **The truck.** Capacity is what decides which loads can be offered to them
 *   at all. A partner registered without one would sit on the job board
 *   accepting work with nothing to haul it in.
 *
 *   **A phone number.** Required here and merely encouraged on the shipper's
 *   form, because a partner is not down the corridor and a load in transit is
 *   not something to email about.
 */
class RegisterTruckerRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'max:40'],

            /**
             * Unique across the whole `users` table, exactly as both other
             * registrations are. An address belongs to one account
             * system-wide, which is what lets the login form stay two fields.
             */
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],

            /**
             * Which fleet — and the app does not send it.
             *
             * A trucker registers with the platform's own fleet wherever in the
             * country they are, so there is nothing to choose and the form does
             * not ask. Kept optional rather than deleted because `companies` is
             * a real table with real tenancy behind it, and an install running
             * two fleets needs some way to say which; see
             * `TruckerRegistrationService::carrier()`.
             *
             * Deliberately no `exists` rule: it would run unscoped against a
             * table the caller has no tenancy for, and a suspended company
             * would pass it and fail later with a worse message. The service
             * resolves it and says which of the two went wrong.
             */
            'company_id' => ['nullable', 'string', 'max:26'],

            'licence_no' => ['required', 'string', 'max:60'],
            'licence_expiry' => ['nullable', 'date', 'after:today'],

            'plate' => ['required', 'string', 'max:20'],
            'model' => ['required', 'string', 'max:120'],
            /**
             * A load is matched against this, so a wrong figure is a partner
             * being offered work their truck cannot take. The ceiling is a
             * sanity bound rather than a legal one: a hundred tonnes is not a
             * truck, it is a typo with an extra zero.
             */
            'capacity_kg' => ['required', 'integer', 'min:100', 'max:100000'],
            'truck_category_id' => ['nullable', 'string', 'max:26'],

            'device_name' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter your name — it is what the fleet books you under.',
            // The default reads as though the address is unavailable, like a
            // username. It is not: there is already an account, and the fix is
            // to sign in.
            'email.unique' => 'That address already has an account. Sign in instead.',
            'company_id.required' => 'Choose the fleet you want to haul for.',
            'licence_no.required' => 'Your licence number is what the fleet checks before approving you.',
            'licence_expiry.after' => 'That licence has expired. Renew it before registering.',
            'plate.required' => 'Add the truck you will be hauling with.',
            'capacity_kg.required' => 'How much can your truck carry? It decides which jobs you are offered.',
        ];
    }

    public function toData(): TruckerRegistrationData
    {
        return TruckerRegistrationData::fromArray($this->validated());
    }
}
