<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Controllers;

use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Tenancy\Requests\NearbyCarriersRequest;
use App\Domain\Tenancy\Resources\CarrierResource;
use App\Domain\Tenancy\Services\CarrierDirectory;
use Illuminate\Http\JsonResponse;

class CarrierController extends ApiController
{
    public function __construct(private readonly CarrierDirectory $directory) {}

    public function __invoke(NearbyCarriersRequest $request): JsonResponse
    {
        $point = $request->point();

        $listings = $this->directory->near(
            lat: $point['lat'] ?? null,
            lng: $point['lng'] ?? null,
            radiusKm: $request->radiusKm(),
            search: $request->search(),
            linkedCompanyIds: [],
        );

        return $this->collection(CarrierResource::collection($listings), $listings);
    }
}
