<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Tenancy\DTO\CarrierListing;
use App\Domain\Tenancy\Services\LogoStore;
use Illuminate\Http\Request;

/** @mixin CarrierListing */
class CarrierResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $company = $this->company;

        return [
            'id' => $company->id,
            'name' => $company->name,
            'code' => $company->code,

            'logo_url' => app(LogoStore::class)->url($company->logo_path),

            'address' => $company->address,
            'contact_phone' => $company->contact_phone,

            'latitude' => $company->latitude,
            'longitude' => $company->longitude,

            'distance_km' => $this->distance_km,

            'vehicles_ready' => $this->vehicles_ready,
            'capacity_kg' => $this->capacity_kg,

            'linked' => $this->linked,
        ];
    }
}
