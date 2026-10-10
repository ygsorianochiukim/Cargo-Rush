<?php

declare(strict_types=1);

namespace App\Domain\Customer\Resources;

use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/** @mixin Customer */
class CustomerResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $login = $this->logins->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact' => $this->contact,

            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'trips_total' => (int) ($this->trips_count ?? $this->trips()->count()),
            'outstanding_cents' => (int) ($this->outstanding_cents ?? $this->outstandingCents()),
            'currency' => 'PHP',
            'rating' => $this->rating,
            'status' => $this->status->value,

            'tin' => $this->tin,
            'vat_treatment' => $this->vatTreatment()->value,
            'vat_label' => $this->vatTreatment()->label(),
            'withholds_tax' => $this->withholdsTax(),
            'withholding_rate_bp' => $this->withholding_rate_bp,

            'login_email' => $login?->email,

            'default_password' => $this->newLogin['password'] ?? null,

            ...$this->stamps(),
        ];
    }
}
