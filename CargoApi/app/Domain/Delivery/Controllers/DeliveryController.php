<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Controllers;

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Delivery\Requests\ProofOfDeliveryRequest;
use App\Domain\Delivery\Resources\DeliveryLogResource;
use App\Domain\Delivery\Services\DeliveryService;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Delivery Logs — the record, the proof, and the pending/active/complete
 * report DESIGN.md section 5.1 asks for.
 */
class DeliveryController extends ApiController
{
    public function __construct(private readonly DeliveryService $deliveries) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->deliveries->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(DeliveryLogResource::collection($page), $page);
    }

    public function show(DeliveryLog $delivery): JsonResponse
    {
        return $this->item(new DeliveryLogResource($delivery));
    }

    /**
     * Proof of delivery, captured in the cab.
     *
     * Multipart, not JSON: the photograph is the substance of the write. The
     * reference is not accepted — the model assigns it.
     */
    public function proof(ProofOfDeliveryRequest $request, DeliveryLog $delivery): JsonResponse
    {
        $this->assertTheirs($request, $delivery);

        return $this->item(new DeliveryLogResource(
            $this->deliveries->attachProof($delivery, $request->toProof()),
        ));
    }

    public function report(): JsonResponse
    {
        return $this->payload($this->deliveries->report());
    }

    /**
     * A driver or partner may only prove their own runs.
     *
     * `delivery.write` is held by everybody who signs a run off at the door,
     * which is every driver — so without this, any of them could attach a
     * photograph to somebody else's delivery, or close another crew's open run
     * through it. The office is not restricted; completing a run on a driver's
     * behalf is part of its job.
     *
     * A 404 rather than a 403, the way the partner endpoints answer: holding an
     * id you were never given should read the same as holding one that does
     * not exist.
     */
    private function assertTheirs(Request $request, DeliveryLog $delivery): void
    {
        $user = $request->user();
        $trip = $delivery->trip;

        $theirs = match ($user->role) {
            Role::Driver->value => $trip !== null && $trip->isCrewedBy($user->driver?->getKey()),
            Role::Trucker->value => $trip !== null && $trip->trucker_id !== null
                && $trip->trucker_id === $user->trucker?->getKey(),
            default => true,
        };

        abort_unless($theirs, 404, 'That delivery is not yours.');
    }
}
