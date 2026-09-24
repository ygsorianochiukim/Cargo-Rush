<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Controllers;

use App\Domain\Billing\Services\PricingService;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Pricing\Requests\DieselPriceRequest;
use App\Domain\Pricing\Requests\PricingCardRequest;
use App\Domain\Pricing\Requests\PricingZoneRequest;
use App\Domain\Pricing\Requests\QuotePreviewRequest;
use App\Domain\Pricing\Requests\TruckCategoryRequest;
use App\Domain\Pricing\Resources\DieselPriceResource;
use App\Domain\Pricing\Resources\PricingBracketResource;
use App\Domain\Pricing\Resources\PricingZoneResource;
use App\Domain\Pricing\Resources\TruckCategoryResource;
use App\Domain\Pricing\Services\PricingZoneService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rate Card — the bands, the pump price, and the quote preview.
 *
 * A zone here is a band of kilometres, the way a subsidy table writes one: A1
 * is 1–40 km, O is 561–600, and the money hangs off the band rather than off a
 * place name.
 *
 * The preview is the endpoint that makes the editor usable. A card typed from a
 * printed table is a card somebody has to check against that table, band by
 * band, and the only way to do that is to ask it what a real run would cost
 * before a customer does.
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

    /**
     * `GET pricing/card` — the firm's plain distance card.
     *
     * The lines that belong to no zone, which apply wherever nothing more
     * specific does. For most hauliers this *is* the rate card: "450 km is
     * ₱5,000", with the zone editor reserved for the firms that genuinely
     * price one route differently from another.
     *
     * Its own endpoint rather than a zone with a magic id, because it is not a
     * zone — it is what a quote falls back to, and giving it a fake place
     * would put a row called "Everywhere" on the zone list for somebody to
     * wonder about.
     */
    public function card(): JsonResponse
    {
        $brackets = $this->zones->card();

        return $this->collection(PricingBracketResource::collection($brackets), $brackets);
    }

    /** `PUT pricing/card` — the whole card at once, as the editor sends it. */
    public function saveCard(PricingCardRequest $request): JsonResponse
    {
        $brackets = $this->zones->saveCard($request->brackets());

        return $this->collection(PricingBracketResource::collection($brackets), $brackets);
    }

    /* ------------------------------------------------- Truck categories */

    /**
     * The kinds of unit the firm runs.
     *
     * The second dimension of the rate card: reefer work carries a premium
     * that has nothing to do with distance, and a card that could only price
     * distance forced the desk to quote that premium off-system.
     */
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

    /**
     * `DELETE pricing/truck-categories/{category}` — remove it, or retire it.
     *
     * One that prices nothing and is on no unit is deleted. One in use is
     * retired, because deleting it would widen every rate-card line naming it
     * to "any truck" — silently changing what a freezer run is quoted, which
     * is the sort of thing nobody notices until an invoice is short.
     */
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
     * What a run would be quoted, which band priced it, and the bands that
     * also cover the distance.
     *
     * A POST for something that changes nothing, which is now the weaker of
     * its two original reasons — the destination that used to make a query
     * string a privacy problem is gone. It stays a POST because the clients
     * calling it do, and a method change would be a breaking one for a
     * screen-level convenience.
     */
    public function quote(QuotePreviewRequest $request): JsonResponse
    {
        $breakdown = $this->pricing->breakdownFor(
            distanceM: $request->distanceMetres(),
            weightKg: (int) $request->integer('weight_kg'),
            truckCategoryId: $request->input('truck_category_id'),
            zoneId: $request->input('pricing_zone_id'),
        );

        return $this->payload($breakdown->toArray());
    }
}
