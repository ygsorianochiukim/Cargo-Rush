<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Controllers;

use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Delivery\Requests\ProofOfDeliveryRequest;
use App\Domain\Delivery\Resources\DeliveryLogResource;
use App\Domain\Delivery\Services\DeliveryService;
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
        return $this->item(new DeliveryLogResource(
            $this->deliveries->attachProof($delivery, $request->toProof()),
        ));
    }

    public function report(): JsonResponse
    {
        return $this->payload($this->deliveries->report());
    }
}
