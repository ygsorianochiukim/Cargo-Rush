<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Controllers;

use App\Domain\Identity\Resources\MeResource;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Trucker\Requests\RegisterTruckerRequest;
use App\Domain\Trucker\Services\TruckerRegistrationService;
use Illuminate\Http\JsonResponse;

class TruckerRegistrationController extends ApiController
{
    public function __construct(private readonly TruckerRegistrationService $registration) {}

    public function __invoke(RegisterTruckerRequest $request): JsonResponse
    {
        ['user' => $user, 'trucker' => $trucker, 'token' => $token] =
            $this->registration->register($request->toData(), $request);

        return $this->item(
            new MeResource($user),
            array_filter([
                'token' => $token,
                'token_type' => $token === null ? null : 'Bearer',
                'trucker_id' => $trucker->getKey(),
                'trucker_status' => $trucker->status->value,
            ], static fn ($value): bool => $value !== null),
            201,
        );
    }
}
