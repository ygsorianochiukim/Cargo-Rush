<?php

declare(strict_types=1);

namespace App\Domain\Identity\Controllers;

use App\Domain\Identity\Requests\LoginRequest;
use App\Domain\Identity\Resources\MeResource;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(LoginRequest $request): JsonResponse
    {
        ['user' => $user, 'token' => $token] = $this->auth->login($request->toData(), $request);

        return $this->item(
            new MeResource($user),
            $token === null ? [] : ['token' => $token, 'token_type' => 'Bearer'],
            201,
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request);

        return $this->noContent();
    }
}
