<?php

declare(strict_types=1);

namespace App\Domain\Identity\Controllers;

use App\Domain\Identity\Requests\ChangePasswordRequest;
use App\Domain\Identity\Requests\ForgotPasswordRequest;
use App\Domain\Identity\Requests\ResetPasswordRequest;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Identity\Services\PasswordService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;

class PasswordController extends ApiController
{
    public function __construct(
        private readonly PasswordService $passwords,
        private readonly AuthService $auth,
    ) {}

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwords->sendResetLink((string) $request->validated('email'));

        return $this->payload([
            'message' => 'If that address has an account, a reset link is on its way to it.',
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwords->reset(
            (string) $request->validated('email'),
            (string) $request->validated('token'),
            (string) $request->validated('password'),
        );

        return $this->payload([
            'message' => 'Your password has been changed. Sign in with the new one.',
        ]);
    }

    public function change(ChangePasswordRequest $request): JsonResponse
    {
        $this->passwords->change(
            $this->auth->requireUser($request),
            (string) $request->validated('current_password'),
            (string) $request->validated('password'),
        );

        return $this->noContent();
    }
}
