<?php

declare(strict_types=1);

namespace App\Domain\Billing\Controllers;

use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Repositories\PaymentRepository;
use App\Domain\Billing\Requests\PaymentRequest;
use App\Domain\Billing\Resources\PaymentResource;
use App\Domain\Billing\Services\PaymentService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends ApiController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentRepository $repository,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->repository->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(PaymentResource::collection($page), $page);
    }

    public function show(Payment $payment): JsonResponse
    {
        return $this->item(new PaymentResource($payment->load('allocations.invoice')));
    }

    public function store(PaymentRequest $request): JsonResponse
    {
        $payment = $this->payments->record(
            $request->payload(),
            $request->allocations(),
            $request->user()?->getAuthIdentifier(),
        );

        return $this->item(new PaymentResource($payment->load('allocations.invoice')), status: 201);
    }

    public function destroy(Payment $payment): JsonResponse
    {
        $this->payments->delete($payment);

        return $this->noContent();
    }
}
