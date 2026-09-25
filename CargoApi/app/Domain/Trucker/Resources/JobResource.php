<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Trip\Models\Trip;
use Illuminate\Http\Request;

/**
 * A load on the job board, as a partner sees it.
 *
 * Deliberately not `TripResource`. That one is the office's view — the crew,
 * the unit, the pricing trace, the billing state — and most of it is either
 * meaningless to somebody deciding whether to take a run or is another
 * company's business to know. What is here is what a person with a truck needs
 * to answer one question: is this worth my afternoon?
 *
 * The money is the part worth being careful about. A board that showed the
 * gross would be quoting a figure nobody receives, so the partner's actual
 * take is computed and shown beside it, with the rate stated plainly. A
 * percentage somebody discovers afterwards is how a platform loses the people
 * it depends on.
 *
 * Every row here is an **offer**: a request a customer held for this partner by
 * name, waiting on them to accept. There is no open board and no unclaimed
 * work — see `JobBoardService::open` for why. So every row pays the same way,
 * and the card can say so without a flag: the partner bills the customer and is
 * charged the haulier's cut. Work the *desk* assigned is not here either — it
 * is already theirs, on their own queue, and it pays the other way round.
 *
 * @mixin Trip
 */
class JobResource extends ApiResource
{
    /**
     * @param  int  $rateBp  What this partner's runs are charged at.
     * @param  int  $takeCents  Their share of this one, worked out by `WalletService`.
     */
    public function __construct(
        Trip $resource,
        private readonly int $rateBp = 0,
        private readonly int $takeCents = 0,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,

            'origin' => $this->origin,
            'destination' => $this->destination,
            'pickup_place' => $this->pickup_place,
            'dropoff_place' => $this->dropoff_place,
            'origin_lat' => $this->origin_lat,
            'origin_lng' => $this->origin_lng,
            'destination_lat' => $this->destination_lat,
            'destination_lng' => $this->destination_lng,

            'cargo' => $this->cargo,
            'weight_kg' => $this->weight_kg,
            'pieces' => $this->pieces,
            'handling' => $this->handling,
            'truck_category' => $this->whenLoaded('truckCategory', fn () => $this->truckCategory?->name),

            'distance_total_m' => $this->distance_total_m,
            /**
             * How far the *pickup* is from the partner right now.
             *
             * Set by the job board when it could measure it and absent
             * otherwise — a straight-line metre count from their last reported
             * pin, which is a sort order rather than an ETA and is described
             * that way on screen.
             */
            'distance_from_m' => $this->distance_from_m ?? null,

            'scheduled_at' => $this->iso($this->scheduled_at),
            'status' => $this->status->value,

            /**
             * What the run bills, what the platform keeps, and what lands with
             * them. All three, in centavos, because the client formats money
             * and the API does not (DESIGN.md section 7.1).
             */
            'price_cents' => $this->price_cents,
            'commission_bp' => $this->rateBp,
            'your_take_cents' => $this->takeCents,
            'currency' => $this->currency,

            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),

            'created_at' => $this->iso($this->created_at),
        ];
    }
}
