<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Controllers;

use App\Domain\Assistant\Models\AssistantDiscordUser;
use App\Domain\Assistant\Models\AssistantPairing;
use App\Domain\Assistant\Requests\AssistantDiscordUserRequest;
use App\Domain\Assistant\Requests\AssistantSettingsRequest;
use App\Domain\Assistant\Services\AssistantPairingService;
use App\Domain\Assistant\Services\AssistantService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AssistantController extends ApiController
{
    public function __construct(
        private readonly AssistantService $assistant,
        private readonly AssistantPairingService $pairings,
    ) {}

    public function show(): JsonResponse
    {
        return $this->page();
    }

    public function update(AssistantSettingsRequest $request): JsonResponse
    {
        $this->assistant->update($request->toInput());

        return $this->page();
    }

    public function storeDiscordUser(AssistantDiscordUserRequest $request): JsonResponse
    {
        $this->assistant->addDiscordUser($request->string('discord_id')->toString(), $request->input('label'));

        return $this->page();
    }

    public function destroyDiscordUser(AssistantDiscordUser $discordUser): JsonResponse
    {
        $this->assistant->removeDiscordUser($discordUser);

        return $this->page();
    }

    public function issueToken(): JsonResponse
    {
        $token = $this->assistant->issueToken();

        return $this->page(['token' => $token]);
    }

    public function revokeToken(): JsonResponse
    {
        $this->assistant->revokeToken();

        return $this->page();
    }

    public function approvePairing(AssistantPairing $pairing): JsonResponse
    {
        $this->pairings->approve($pairing);

        return $this->page();
    }

    public function dismissPairing(AssistantPairing $pairing): JsonResponse
    {
        $this->pairings->dismiss($pairing);

        return $this->page();
    }

    public function requestPairing(Request $request): JsonResponse
    {
        return $this->payload($this->pairings->request($request->ip()))->setStatusCode(201);
    }

    public function pollPairing(Request $request, AssistantPairing $pairing): JsonResponse
    {
        return $this->payload($this->pairings->poll($pairing, (string) $request->header('X-Pairing-Secret', '')));
    }

    public function botConfig(Request $request): JsonResponse
    {
        $this->mustBeTheBot($request);

        return $this->payload($this->assistant->botConfig());
    }

    public function botStatus(Request $request): JsonResponse
    {
        $this->mustBeTheBot($request);

        $status = $request->validate([
            'state' => ['required', 'in:online,connecting,no_token,refused'],
            'tag' => ['nullable', 'string', 'max:100'],
            'servers' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'invite_url' => ['nullable', 'url', 'max:300'],
            'error' => ['nullable', 'string', 'max:300'],
        ]);

        $this->assistant->reportStatus($status);

        return $this->noContent();
    }

    private function mustBeTheBot(Request $request): void
    {
        $token = $request->user()?->currentAccessToken();

        abort_unless(
            $token instanceof PersonalAccessToken && $this->assistant->isBotToken($token),
            403,
            'Only the Discord assistant token can read this.',
        );
    }

    /** @param array<string, mixed> $meta */
    private function page(array $meta = []): JsonResponse
    {
        return $this->payload([...$this->assistant->overview(), 'pairings' => $this->pairings->waiting()], $meta);
    }
}
