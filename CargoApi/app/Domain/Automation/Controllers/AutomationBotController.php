<?php

declare(strict_types=1);

namespace App\Domain\Automation\Controllers;

use App\Domain\Assistant\Services\AssistantService;
use App\Domain\Assistant\Services\DiscordTestAlert;
use App\Domain\Automation\Requests\AutomationBotSettingsRequest;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AutomationBotController extends ApiController
{
    public function __construct(private readonly AssistantService $assistant, private readonly DiscordTestAlert $testAlert) {}

    public function show(Request $request): JsonResponse
    {
        $this->requireOperator($request);

        return $this->configuration();
    }

    public function update(AutomationBotSettingsRequest $request): JsonResponse
    {
        $this->requireOperator($request);
        $this->assistant->update($request->validated());

        return $this->configuration();
    }

    public function test(Request $request): JsonResponse
    {
        $this->requireOperator($request);
        $input = $request->validate(['target' => ['required', 'in:finance,hr']]);

        return $this->payload($this->testAlert->queue($input['target']))->setStatusCode(202);
    }

    private function configuration(): JsonResponse
    {
        return $this->payload([...$this->assistant->automationSettings(), 'test_alert' => $this->testAlert->latest()]);
    }

    private function requireOperator(Request $request): void
    {
        $token = $request->user()->currentAccessToken();
        abort_if($token instanceof PersonalAccessToken && in_array(AssistantService::ABILITY, $token->abilities, true), 403);
    }
}
