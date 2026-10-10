<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Services\CarrierDirectory;

class NearbyCarriersRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],

            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:'.CarrierDirectory::MAX_RADIUS_KM],

            'search' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'lat.required_with' => 'A longitude needs its latitude.',
            'lng.required_with' => 'A latitude needs its longitude.',
        ];
    }

    public function point(): ?array
    {
        if (! $this->filled('lat') || ! $this->filled('lng')) {
            return null;
        }

        return ['lat' => (float) $this->input('lat'), 'lng' => (float) $this->input('lng')];
    }

    public function radiusKm(): ?float
    {
        return $this->filled('radius_km') ? (float) $this->input('radius_km') : null;
    }

    public function search(): ?string
    {
        $term = trim((string) $this->input('search', ''));

        return $term === '' ? null : $term;
    }
}
