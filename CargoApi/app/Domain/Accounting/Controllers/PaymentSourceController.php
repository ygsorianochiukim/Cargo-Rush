<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Controllers;

use App\Domain\Accounting\Models\PaymentSource;
use App\Domain\Accounting\Requests\PaymentSourceRequest;
use App\Domain\Accounting\Resources\PaymentSourceResource;
use App\Domain\Accounting\Services\PaymentSourceService;
use App\Domain\Shared\Enums\PaymentSourceKind;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentSourceController extends ApiController
{
    public function __construct(private readonly PaymentSourceService $sources) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->sources->paginate(
            [...$this->filters($request), ...$request->only(['kind', 'active'])],
            $this->perPage($request, 100),
        );

        return $this->collection(PaymentSourceResource::collection($page), $page);
    }

    public function kinds(): JsonResponse
    {
        return $this->payload(array_map(
            static fn (PaymentSourceKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()],
            PaymentSourceKind::cases(),
        ));
    }

    public function show(PaymentSource $paymentSource): JsonResponse
    {
        return $this->item(new PaymentSourceResource($paymentSource));
    }

    public function store(PaymentSourceRequest $request): JsonResponse
    {
        return $this->item(
            new PaymentSourceResource($this->sources->create($request->toData())),
            status: 201,
        );
    }

    public function update(PaymentSourceRequest $request, PaymentSource $paymentSource): JsonResponse
    {
        return $this->item(new PaymentSourceResource($this->sources->update($paymentSource, $request->toData())));
    }

    public function destroy(PaymentSource $paymentSource): JsonResponse
    {
        if ($this->sources->retire($paymentSource)) {
            return $this->noContent();
        }

        return $this->item(
            new PaymentSourceResource($paymentSource->refresh()),
            ['retired' => true, 'reason' => 'Spend has been paid from this, so it was switched off rather than deleted.'],
        );
    }
}
