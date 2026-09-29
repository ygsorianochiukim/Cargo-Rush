<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Trucker\DTO\TruckerRegistrationData;
use Illuminate\Validation\Rules\Password;

/**
 * A trucking service signing itself up — the third public write.
 *
 * Who they are and how to reach them, and a login. Nothing about trucks or
 * drivers: those are added from the app once the office has approved the
 * account, because a registration nobody has vetted has no business putting
 * units on the books.
 *
 *   **The business name.** What the office and customers know them as, and the
 *   label every one of their drivers carries so nobody mistakes them for Cargo
 *   Rush's own.
 *
 *   **A phone number.** Required here and merely encouraged on the shipper's
 *   form, because a trucker is not down the corridor.
 *
 *   **The licence** is optional. The owner may never drive; each driver they
 *   add carries their own.
 */
class RegisterTruckerRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'max:40'],
            'business_name' => ['required', 'string', 'max:160'],

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
             * There is one company, and the API resolves it; see
             * `TruckerRegistrationService::carrier()`. Kept so an install with
             * more than one can still say which.
             */
            'company_id' => ['nullable', 'string', 'max:26'],

            'licence_no' => ['nullable', 'string', 'max:60'],
            'licence_expiry' => ['nullable', 'date', 'after:today'],

            'device_name' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter your name — it is what the fleet books you under.',
            'business_name.required' => 'Enter the name of your trucking service.',
            // The default reads as though the address is unavailable, like a
            // username. It is not: there is already an account, and the fix is
            // to sign in.
            'email.unique' => 'That address already has an account. Sign in instead.',
            'licence_expiry.after' => 'That licence has expired. Renew it before registering.',
        ];
    }

    public function toData(): TruckerRegistrationData
    {
        return TruckerRegistrationData::fromArray($this->validated());
    }
}
