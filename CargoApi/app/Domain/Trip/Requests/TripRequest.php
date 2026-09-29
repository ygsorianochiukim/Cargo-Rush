<?php

declare(strict_types=1);

namespace App\Domain\Trip\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trip\DTO\TripData;
use App\Domain\Trip\Requests\Concerns\GuardsTheTypedPrice;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TripRequest extends ApiFormRequest
{
    use GuardsTheTypedPrice;

    /**
     * The statuses the office owns.
     *
     * Booking work is the office's job; reporting what happened to it on the
     * road is the driver's. So `in_transit` and `delivered` are missing here
     * deliberately — they are reached by the driver leaving on the run
     * (`POST trips/{trip}/start`) and handing it over
     * (`POST trips/current/deliver`), each of which does more than set a
     * column: they open the dispatch record, file the delivery log with its
     * proof, and credit the driver. A form that could set either would
     * produce a trip that says `delivered` with no proof behind it.
     *
     * The one exception is `delivered` for a trip that already happened, and
     * it is not the column being set — see the entry below.
     *
     * `overdue` is absent for a different reason: it is derived from the ETA
     * against the clock by `cargo:trips-overdue`, so typing it in would only
     * be overwritten.
     */
    private const OFFICE_SETTABLE = [
        StatusValue::Scheduled->value,
        StatusValue::Assigned->value,
        StatusValue::Pending->value,
        StatusValue::Cancelled->value,
        /**
         * For a trip that already happened, entered afterwards.
         *
         * Not a column the form sets: the controller runs the trip through
         * the real delivery, dated to `delivered_at` — the log, the day's
         * sheet, the wallet and the invoice — so an old trip is on the books
         * exactly as if a driver had closed it that day. Only for a trip
         * whose date has passed; a future one is delivered by driving it.
         */
        StatusValue::Delivered->value,
    ];

    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            'customer_id' => ['nullable', 'string', 'exists:customers,id'],
            'origin' => [$required, 'string', 'max:160'],
            // Coordinates are optional — a trip booked over the phone has a
            // place name long before anybody has pinned it — but a lone
            // latitude is not a location, so each requires its pair.
            'origin_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:origin_lng'],
            'origin_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:origin_lat'],
            'destination' => [$required, 'string', 'max:160'],
            'destination_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:destination_lng'],
            'destination_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:destination_lat'],
            'cargo' => [$required, 'string', 'max:255'],
            'weight_kg' => [$required, 'integer', 'min:0', 'max:60000'],
            'pieces' => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'handling' => ['nullable', 'string', 'max:255'],
            // The zone card quotes the price (`PricingService`), so this is
            // not a field the booking form normally sends. It is here for the
            // one case the card cannot cover: a rate the office negotiated, or
            // a run no zone line reaches. Zero is meaningful and is kept —
            // that is how the company's own freight is booked. Null hands the
            // run back to the card. `pricing.manage` only; see the trait.
            'price_cents' => $this->priceRules(),
            'driver_id' => ['nullable', 'string', 'exists:drivers,id'],
            /**
             * Who rides along — any number up to a truck's worth, or none.
             *
             * Each once, and never the driver: the same person twice would be
             * paid for the run twice, and a helper who is also the driver is a
             * data-entry slip, not a crew.
             */
            'helper_ids' => ['sometimes', 'nullable', 'array', 'max:5'],
            'helper_ids.*' => ['string', 'distinct', 'exists:drivers,id', 'different:driver_id'],
            'vehicle_id' => ['nullable', 'string', 'exists:vehicles,id'],

            /**
             * The kind of unit this job needs — asked for at booking.
             *
             * A *requirement*, not a fact about the assigned vehicle: a trip is
             * quoted the moment it is booked, usually before any unit is
             * picked, and a customer asking for a freezer is owed the freezer
             * price whatever rolls out of the yard three days later.
             *
             * Scoped to the caller's company — `exists` runs outside the tenant
             * scope, and a category from another firm would price nothing.
             */
            'truck_category_id' => [
                'nullable', 'string',
                Rule::exists('truck_categories', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
            /**
             * The band this run is to be priced in, where the desk has chosen
             * one.
             *
             * A zone is a band of kilometres — A1 is 1–40 km — and the
             * distance picks it on its own for most runs. This is here for the
             * case the distance cannot decide: a subsidy table routinely holds
             * two bands over the same kilometres at different money (A1 beside
             * A2, E1 beside E2), and which of the two applies is a commercial
             * call about that particular job. Left out, the table's own order
             * decides, which is the lower figure of the two.
             *
             * The same column the price trace is written to, and deliberately.
             * The band somebody picked and the band that priced the trip are
             * one fact, and a second column for it would be two answers to
             * "which band is this?" with nothing to say which the invoice used.
             *
             * Scoped to the caller's company, like the category above: `exists`
             * runs outside the tenant scope.
             */
            'pricing_zone_id' => [
                'nullable', 'string',
                Rule::exists('pricing_zones', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
            'status' => ['sometimes', Rule::in(self::OFFICE_SETTABLE)],
            // A past trip only. `delivered_at` defaults to the scheduled time.
            'delivered_at' => ['nullable', 'date', 'before_or_equal:now'],
            'receiver_name' => ['nullable', 'string', 'max:120'],
            'pickup_place' => ['nullable', 'string', 'max:255'],
            'dropoff_place' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => [$required, 'date'],
            // An ETA before the unit even leaves cannot be right.
            'eta' => ['nullable', 'date', 'after_or_equal:scheduled_at'],
            'distance_total_m' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Delivered is for the past only, and a delivery cannot come before the
     * trip. Checked against the scheduled time being sent, or the trip's own
     * when an edit leaves it out.
     */
    public function after(): array
    {
        return [$this->mustBeAllowedToPrice(...), $this->oneHaulierOnly(...), function (Validator $validator): void {
            if ($this->input('status') !== StatusValue::Delivered->value) {
                return;
            }

            $scheduled = $this->input('scheduled_at') ?? $this->route('trip')?->scheduled_at;

            if ($scheduled === null) {
                return;
            }

            $scheduled = Carbon::parse($scheduled);

            if ($scheduled->isFuture()) {
                $validator->errors()->add('status', 'Only a trip that already happened can be entered as delivered.');

                return;
            }

            if ($this->filled('delivered_at') && Carbon::parse($this->input('delivered_at'))->lt($scheduled)) {
                $validator->errors()->add('delivered_at', 'It cannot have been delivered before it was scheduled.');
            }
        }];
    }

    /**
     * A fleet unit or a trucker, never both.
     *
     * A trucker hauls in their own truck, and the company keeps only its
     * commission on the run. A fleet `vehicle_id` beside them is what once
     * booked the whole price as company income, so it is refused here —
     * against a `trucker_id` sent with it, or one already on the trip being
     * edited. Taking the trucker off (Release) comes first. A form re-sending
     * the unit an older row already carries is let through, so its notes can
     * still be saved.
     */
    private function oneHaulierOnly(Validator $validator): void
    {
        $trip = $this->route('trip');

        if (! $this->filled('vehicle_id') || $this->input('vehicle_id') === $trip?->vehicle_id) {
            return;
        }

        if ($this->filled('trucker_id') || $trip?->trucker_id !== null) {
            $validator->errors()->add(
                'vehicle_id',
                'A trucker is hauling this run in their own truck, so it cannot go out on a fleet unit as well. Release the trucker first.',
            );
        }
    }

    public function messages(): array
    {
        return [
            'helper_ids.*.different' => 'A helper cannot be the same person as the driver.',
            'helper_ids.*.distinct' => 'The same helper is named twice.',
            'helper_ids.max' => 'A run can carry at most five helpers.',
            'eta.after_or_equal' => 'The ETA cannot be earlier than the scheduled departure.',
            'origin_lat.required_with' => 'A longitude needs its latitude.',
            'origin_lng.required_with' => 'A latitude needs its longitude.',
            'destination_lat.required_with' => 'A longitude needs its latitude.',
            'destination_lng.required_with' => 'A latitude needs its longitude.',
        ];
    }

    public function toData(): TripData
    {
        return TripData::fromArray($this->payload());
    }

    /**
     * What was validated, less a re-sent price that did not move — so a form
     * re-sending the card's own figure does not mark the run as hand-priced.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->withoutUnmovedPrice($this->validated());
    }
}
