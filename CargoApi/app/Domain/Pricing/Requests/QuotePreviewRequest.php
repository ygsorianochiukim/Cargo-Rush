<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;

class QuotePreviewRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'distance_m' => ['sometimes', 'integer', 'min:0'],
            'distance_km' => ['sometimes', 'numeric', 'min:0'],
            'weight_kg' => ['sometimes', 'integer', 'min:0'],

            'truck_category_id' => [
                'nullable', 'string',
                Rule::exists('truck_categories', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],

            'pricing_zone_id' => [
                'nullable', 'string',
                Rule::exists('pricing_zones', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function distanceMetres(): int
    {
        if ($this->filled('distance_km')) {
            return (int) round((float) $this->input('distance_km') * 1000);
        }

        return (int) $this->integer('distance_m');
    }
}
