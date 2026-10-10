<?php

declare(strict_types=1);

namespace App\Domain\Customer\Controllers;

use App\Domain\Customer\Requests\RegisterShipperRequest;
use App\Domain\Customer\Services\ShipperRegistrationService;
use App\Domain\Identity\Resources\MeResource;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;

class ShipperRegistrationController extends ApiController
{
    public function __construct(private readonly ShipperRegistrationService $registration) {}

    public function __invoke(RegisterShipperRequest $request): JsonResponse
    {
        ['user' => $user, 'token' => $token] = $this->registration->register($request->toData(), $request);

        return $this->item(
            new MeResource($user),
            $token === null ? [] : ['token' => $token, 'token_type' => 'Bearer'],
            201,
        );
    }
}
