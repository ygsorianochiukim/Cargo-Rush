<?php

declare(strict_types=1);

namespace App\Domain\Trip\Resources;

use App\Domain\Inspection\Services\InspectionService;
use App\Domain\Shared\Enums\BookingSource;
use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Trip\Models\Trip;
use Illuminate\Http\Request;

/** @mixin Trip */
class TripResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'origin' => $this->origin,
            'origin_lat' => $this->origin_lat,
            'origin_lng' => $this->origin_lng,
            'destination' => $this->destination,
            'destination_lat' => $this->destination_lat,
            'destination_lng' => $this->destination_lng,
            'mapped' => $this->isMapped(),
            'cargo' => $this->cargo,
            'weight_kg' => $this->weight_kg,
            'pieces' => $this->pieces,
            'handling' => $this->handling,

            'price_cents' => $this->price_cents,
            'currency' => $this->currency,

            'needs_zone' => $this->price_cents === null,
            'pricing_note' => $this->pricing_note,
            'pricing_source' => $this->pricing_source,
            'manually_priced' => $this->pricing_source === 'manual',

            'customer_id' => $this->customer_id,
            'customer' => $this->customer?->name,
            'driver_id' => $this->driver_id,
            'driver_name' => $this->driver?->name,
            'helper_ids' => $this->helpers->pluck('id')->all(),
            'helpers' => $this->helpers
                ->map(static fn ($helper): array => ['id' => $helper->id, 'name' => $helper->name])
                ->all(),
            'vehicle_id' => $this->vehicle_id,
            'vehicle_plate' => $this->vehicle?->plate,

            'hauled_by' => $this->hauledByPartner() ? 'trucker' : 'company',
            'trucker_id' => $this->trucker_id,
            'truck_category_id' => $this->truck_category_id,
            'trucker_name' => $this->trucker?->name,
            'trucker_business_name' => $this->trucker?->business_name,
            'trucker_phone' => $this->trucker?->phone,
            'trucker_vehicle_id' => $this->trucker_vehicle_id,
            'trucker_plate' => $this->truckerVehicle?->plate,
            'trucker_driver_id' => $this->trucker_driver_id,
            'trucker_driver_name' => $this->whenLoaded('truckerDriver', fn () => $this->truckerDriver?->name),

            'booking_source' => ($this->booking_source ?? BookingSource::CargoRush)->value,
            'booking_source_label' => ($this->booking_source ?? BookingSource::CargoRush)->label(),
            'commission_bp' => $this->commission_bp,
            'commission_cents' => $this->commission_cents,

            'inspection' => app(InspectionService::class)->summaryFor($this->resource, $this->detailedInspection()),

            'status' => $this->status->value,
            'pickup_place' => $this->pickup_place,
            'dropoff_place' => $this->dropoff_place,
            'scheduled_at' => $this->iso($this->scheduled_at),
            'eta' => $this->iso($this->eta),
            'distance_total_m' => $this->distance_total_m,
            'distance_source' => $this->distance_source,
            'billed_at' => $this->iso($this->billed_at),
            'dispatch_checklist' => $this->dispatch_checked_at === null ? null : [
                'answers' => $this->dispatch_checklist,
                'remarks' => $this->dispatch_remarks,
                'checked_at' => $this->iso($this->dispatch_checked_at),
                'checked_by' => $this->dispatch_checked_by,
            ],
            'has_pod_photo' => $this->whenLoaded(
                'deliveryLog',
                fn (): bool => $this->deliveryLog?->pod_image_path !== null,
            ),

            ...$this->stamps(),
        ];
    }

    protected function detailedInspection(): bool
    {
        return true;
    }
}
