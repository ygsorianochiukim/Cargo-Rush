<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Controllers;

use App\Domain\Assistant\Models\AssistantDiscordUser;
use App\Domain\Assistant\Models\AssistantPairing;
use App\Domain\Assistant\Models\AssistantSetting;
use App\Domain\Assistant\Requests\AssistantDiscordUserRequest;
use App\Domain\Assistant\Requests\AssistantSettingsRequest;
use App\Domain\Assistant\Services\AssistantPairingService;
use App\Domain\Assistant\Services\AssistantService;
use App\Domain\Assistant\Services\DiscordFinanceActivity;
use App\Domain\Assistant\Services\DiscordTestAlert;
use App\Domain\Identity\Models\Permission;
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
        $input = $request->toInput();
        $developerFields = [...AssistantSetting::CHANNEL_FIELDS, 'discord_payment_alert_pesos'];
        abort_if(
            array_intersect_key($input, array_flip($developerFields)) !== []
                && ! $request->user()->hasPermission(Permission::DEVELOPER_ACCESS),
            403,
            'Developer Access is required to manage Discord channels and payment alerts.',
        );

        $this->assistant->update($input);

        return $this->page();
    }

    public function storeDiscordUser(AssistantDiscordUserRequest $request): JsonResponse
    {
        $this->assistant->addDiscordUser(
            $request->string('discord_id')->toString(),
            $request->input('label'),
            $request->has('may_confirm_receipts') ? $request->boolean('may_confirm_receipts') : null,
        );

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
            'finance_error' => ['nullable', 'string', 'max:300'],
            'hr_error' => ['nullable', 'string', 'max:300'],
        ]);

        $this->assistant->reportStatus($status);

        return $this->noContent();
    }

    public function financeActivity(Request $request, DiscordFinanceActivity $activity): JsonResponse
    {
        $this->mustBeTheBot($request);
        $settings = $this->assistant->settings();
        abort_unless($settings->enabled && $settings->discord_finance_channel_id !== null
            && $request->user()->hasPermission('billing.view'), 403);

        $input = $request->validate([
            'day' => ['required', 'date_format:Y-m-d'],
            'timezone' => ['required', 'timezone'],
            'kind' => ['required', 'in:runs,changes,payments,receipt-expenses,aged-receivables'],
            'alerts' => ['sometimes', 'boolean'],
            'after' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9]{1,26}$/'],
        ]);

        return $this->payload($activity->page($settings, $input));
    }

    public function pendingTestAlert(Request $request, DiscordTestAlert $testAlert): JsonResponse
    {
        $this->mustBeTheBot($request);

        return $this->payload(['test' => $testAlert->pending()]);
    }

    public function finishTestAlert(Request $request, DiscordTestAlert $testAlert, string $test): JsonResponse
    {
        $this->mustBeTheBot($request);
        $input = $request->validate([
            'status' => ['required', 'in:delivered,failed'],
            'guild_id' => ['required_if:status,delivered', 'string', 'regex:/^[1-9][0-9]{16,19}$/'],
            'message_id' => ['required_if:status,delivered', 'string', 'regex:/^[1-9][0-9]{16,19}$/'],
        ]);
        $testAlert->finish($test, $input['status'], $input['guild_id'] ?? null, $input['message_id'] ?? null);

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
        $overview = $this->assistant->overview();
        if (! request()->user()->hasPermission(Permission::DEVELOPER_ACCESS)) {
            foreach ([...AssistantSetting::CHANNEL_FIELDS, 'discord_payment_alert_pesos'] as $field) {
                unset($overview[$field]);
            }
        }

        return $this->payload([...$overview, 'pairings' => $this->pairings->waiting()], $meta);
    }
}
