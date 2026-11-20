<?php

declare(strict_types=1);

namespace App\Domain\Automation\Controllers;

use App\Domain\Assistant\Services\AssistantService;
use App\Domain\Automation\Requests\AutomationBotSettingsRequest;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AutomationBotController extends ApiController
{
    public function __construct(private readonly AssistantService $assistant) {}

    public function show(Request $request): JsonResponse
    {
        $this->requireOperator($request);

        return $this->payload($this->assistant->automationSettings());
    }

    public function update(AutomationBotSettingsRequest $request): JsonResponse
    {
        $this->requireOperator($request);
        $this->assistant->update($request->validated());

        return $this->payload($this->assistant->automationSettings());
    }

    private function requireOperator(Request $request): void
    {
        $token = $request->user()->currentAccessToken();
        abort_if($token instanceof PersonalAccessToken && in_array(AssistantService::ABILITY, $token->abilities, true), 403);
    }
}
