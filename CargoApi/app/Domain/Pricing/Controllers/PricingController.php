<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Controllers;

use App\Domain\Billing\Services\PricingService;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Pricing\Models\TruckSize;
use App\Domain\Pricing\Requests\DieselPriceRequest;
use App\Domain\Pricing\Requests\PricingCardRequest;
use App\Domain\Pricing\Requests\PricingZoneRequest;
use App\Domain\Pricing\Requests\QuotePreviewRequest;
use App\Domain\Pricing\Requests\TruckCategoryRequest;
use App\Domain\Pricing\Requests\TruckSizeRequest;
use App\Domain\Pricing\Resources\DieselPriceResource;
use App\Domain\Pricing\Resources\PricingBracketResource;
use App\Domain\Pricing\Resources\PricingZoneResource;
use App\Domain\Pricing\Resources\TruckCategoryResource;
use App\Domain\Pricing\Resources\TruckSizeResource;
use App\Domain\Pricing\Services\PricingZoneService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingController extends ApiController
{
    public function __construct(
        private readonly PricingZoneService $zones,
        private readonly PricingService $pricing,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $zones = $this->zones->list($this->filters($request));

        return $this->collection(PricingZoneResource::collection($zones), $zones);
    }

    public function card(): JsonResponse
    {
        $brackets = $this->zones->card();

        return $this->collection(PricingBracketResource::collection($brackets), $brackets);
    }

    public function saveCard(PricingCardRequest $request): JsonResponse
    {
        $brackets = $this->zones->saveCard($request->brackets());

        return $this->collection(PricingBracketResource::collection($brackets), $brackets);
    }

    public function truckCategories(Request $request): JsonResponse
    {
        $categories = TruckCategory::query()
            ->withCount(['brackets', 'vehicles'])
            ->when($request->boolean('active'), fn ($query) => $query->active())
            ->inCardOrder()
            ->get();

        return $this->collection(TruckCategoryResource::collection($categories), $categories);
    }

    public function storeTruckCategory(TruckCategoryRequest $request): JsonResponse
    {
        return $this->item(
            new TruckCategoryResource($this->zones->createTruckCategory($request->toAttributes())),
            status: 201,
        );
    }

    public function updateTruckCategory(
        TruckCategoryRequest $request,
        TruckCategory $truckCategory,
    ): JsonResponse {
        $truckCategory->update($request->toAttributes());

        return $this->item(new TruckCategoryResource($truckCategory->refresh()));
    }

    public function truckSizes(Request $request): JsonResponse
    {
        $sizes = TruckSize::query()
            ->withCount('vehicles')
            ->when($request->boolean('active'), fn ($query) => $query->active())
            ->smallestFirst()
            ->get();

        return $this->collection(TruckSizeResource::collection($sizes), $sizes);
    }

    public function storeTruckSize(TruckSizeRequest $request): JsonResponse
    {
        return $this->item(
            new TruckSizeResource($this->zones->createTruckSize($request->toAttributes())),
            status: 201,
        );
    }

    public function updateTruckSize(TruckSizeRequest $request, TruckSize $truckSize): JsonResponse
    {
        $truckSize->update($request->toAttributes());
        $truckSize->sizeUnsizedVehicles();

        return $this->item(new TruckSizeResource($truckSize->refresh()));
    }

    public function destroyTruckSize(TruckSize $truckSize): JsonResponse
    {
        if ($this->zones->deleteTruckSize($truckSize)) {
            return $this->noContent();
        }

        return $this->item(
            new TruckSizeResource($truckSize->refresh()),
            meta: [
                'retired' => true,
                'reason' => 'This size is on units or trips, so it has been retired rather than deleted.',
            ],
        );
    }

    public function destroyTruckCategory(TruckCategory $truckCategory): JsonResponse
    {
        if ($this->zones->deleteTruckCategory($truckCategory)) {
            return $this->noContent();
        }

        return $this->item(
            new TruckCategoryResource($truckCategory->refresh()),
            meta: [
                'retired' => true,
                'reason' => 'This category is on the rate card or on units in the fleet, so it has '
                    .'been retired rather than deleted. Existing prices are untouched.',
            ],
        );
    }

    public function show(PricingZone $zone): JsonResponse
    {
        return $this->item(new PricingZoneResource($zone->load('brackets')));
    }

    public function store(PricingZoneRequest $request): JsonResponse
    {
        return $this->item(
            new PricingZoneResource($this->zones->create($request->toData())),
            status: 201,
        );
    }

    public function update(PricingZoneRequest $request, PricingZone $zone): JsonResponse
    {
        return $this->item(new PricingZoneResource($this->zones->update($zone, $request->toData())));
    }

    public function destroy(PricingZone $zone): JsonResponse
    {
        $this->zones->delete($zone);

        return $this->noContent();
    }

    public function diesel(): JsonResponse
    {
        return $this->payload([
            ...$this->zones->dieselState(),
            'history' => DieselPriceResource::collection($this->zones->dieselHistory())->resolve(),
        ]);
    }

    public function storeDiesel(DieselPriceRequest $request): JsonResponse
    {
        $price = $this->zones->recordDiesel($request->validated(), $request->user()?->id);

        return $this->item(new DieselPriceResource($price), status: 201);
    }

    public function quote(QuotePreviewRequest $request): JsonResponse
    {
        $breakdown = $this->pricing->breakdownFor(
            distanceM: $request->distanceMetres(),
            weightKg: (int) $request->integer('weight_kg'),
            truckCategoryId: $request->input('truck_category_id'),
            truckSizeId: $request->input('truck_size_id'),
            zoneId: $request->input('pricing_zone_id'),
            pinned: $request->filled('pricing_zone_id'),
        );

        return $this->payload($breakdown->toArray());
    }
}
