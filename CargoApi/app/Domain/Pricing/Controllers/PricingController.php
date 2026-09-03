<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Controllers;

use App\Domain\Billing\Services\PricingService;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Requests\DieselPriceRequest;
use App\Domain\Pricing\Requests\PricingZoneRequest;
use App\Domain\Pricing\Requests\QuotePreviewRequest;
use App\Domain\Pricing\Resources\DieselPriceResource;
use App\Domain\Pricing\Resources\PricingZoneResource;
use App\Domain\Pricing\Services\PricingZoneService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rate Card — the zone editor, the pump price, and the quote preview.
 *
 * The preview is the endpoint that makes the editor usable. Rates are a thing
 * people get wrong in the third decimal place, and the only way to know a card
 * is right is to ask it what a real run would cost before a customer does.
 */
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

    /* ------------------------------------------------------------- Diesel */

    /** What diesel costs, what the card assumes, and the resulting swing. */
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

    /* ------------------------------------------------------------- Preview */

    /**
     * What a run would be quoted, and by which bracket.
     *
     * A GET would have been the obvious choice for something that changes
     * nothing, and it is a POST anyway: a destination is free text that can
     * contain anything a caller said down a phone line, and putting that in a
     * query string means logging customer addresses into every access log.
     */
    public function quote(QuotePreviewRequest $request): JsonResponse
    {
        $breakdown = $this->pricing->breakdownFor(
            distanceM: $request->distanceMetres(),
            weightKg: (int) $request->integer('weight_kg'),
            destination: $request->input('destination'),
            origin: $request->input('origin'),
        );

        return $this->payload($breakdown->toArray());
    }
}
