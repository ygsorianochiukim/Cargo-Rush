<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Controllers;

use App\Domain\Identity\Resources\MeResource;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Tenancy\Requests\RegisterCompanyRequest;
use App\Domain\Tenancy\Services\RegistrationService;
use Illuminate\Http\JsonResponse;

class RegistrationController extends ApiController
{
    public function __construct(private readonly RegistrationService $registration) {}

    public function __invoke(RegisterCompanyRequest $request): JsonResponse
    {
        ['user' => $user, 'token' => $token] = $this->registration->register($request->toData(), $request);

        return $this->item(
            new MeResource($user),
            $token === null ? [] : ['token' => $token, 'token_type' => 'Bearer'],
            201,
        );
    }
}
