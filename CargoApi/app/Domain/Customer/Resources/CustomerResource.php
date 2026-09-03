<?php

declare(strict_types=1);

namespace App\Domain\Customer\Resources;

use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Customer
 */
class CustomerResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $login = $this->logins->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact' => $this->contact,
            // Both come from the repository as subquery columns; the fallback
            // covers a single record fetched without them.
            'trips_total' => (int) ($this->trips_count ?? $this->trips()->count()),
            'outstanding_cents' => (int) ($this->outstanding_cents ?? $this->outstandingCents()),
            'currency' => 'PHP',
            'rating' => $this->rating,
            'status' => $this->status->value,

            // What the firm signs in with, or null for one that cannot: the
            // list shows this because "can this customer file their own
            // requests?" is otherwise invisible from the office.
            'login_email' => $login?->email,

            /**
             * The starting password, on the one response that just created the
             * account — see `Customer::$newLogin`. Null on every read, because
             * a password is not a field of a customer record; this is the
             * office being told once what to pass on, in the reply to the form
             * they filled in.
             */
            'default_password' => $this->newLogin['password'] ?? null,

            ...$this->stamps(),
        ];
    }
}
