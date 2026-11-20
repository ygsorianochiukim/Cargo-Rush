<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Services;

use App\Domain\Assistant\Models\AssistantDiscordUser;
use App\Domain\Assistant\Models\AssistantSetting;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AssistantService
{
    public const ABILITY = 'assistant.bot';

    private const TOKEN_NAME = 'discord-assistant';

    public function __construct(private readonly Tenant $tenant) {}

    public function settings(): AssistantSetting
    {
        return AssistantSetting::query()->firstOrCreate([]);
    }

    /** @param array<string, mixed> $input */
    public function update(array $input): AssistantSetting
    {
        $id = $this->settings()->getKey();

        return DB::transaction(function () use ($id, $input): AssistantSetting {
            $settings = AssistantSetting::query()->lockForUpdate()->findOrFail($id);
            $settings->fill(array_intersect_key($input, array_flip([
                'enabled', 'llm_provider', 'llm_model', 'llm_thinking', 'bot_role',
                ...AssistantSetting::CHANNEL_FIELDS, 'discord_payment_alert_pesos',
            ])));

            $key = isset($input['api_key']) ? trim((string) $input['api_key']) : '';

            if ($key !== '') {
                $settings->llm_api_key = $key;
                $settings->llm_key_hint = mb_substr($key, -4);
            } elseif (($input['clear_key'] ?? false) === true) {
                $settings->llm_api_key = null;
                $settings->llm_key_hint = null;
            }

            $discord = isset($input['discord_token']) ? trim((string) $input['discord_token']) : '';

            if ($discord !== '') {
                $settings->discord_token = $discord;
                $settings->discord_token_hint = mb_substr($discord, -4);
            } elseif (($input['clear_discord_token'] ?? false) === true) {
                $settings->discord_token = null;
                $settings->discord_token_hint = null;
            }

            $channels = array_filter($settings->only(AssistantSetting::CHANNEL_FIELDS));
            foreach ($channels as $field => $channel) {
                if ($settings->isDirty($field) && in_array($channel, array_diff_key($channels, [$field => true]), true)) {
                    throw ValidationException::withMessages([$field => 'Use a different Discord channel for each destination.']);
                }
            }

            $settings->save();

            if ($settings->wasChanged('bot_role')) {
                $settings->botUser?->update(['role' => $settings->bot_role]);
            }

            return $settings->refresh();
        });
    }

    public function addDiscordUser(string $discordId, ?string $label, ?bool $mayConfirmReceipts = null): AssistantDiscordUser
    {
        return AssistantDiscordUser::query()->updateOrCreate(
            ['discord_id' => $discordId],
            [
                'label' => $label !== null && trim($label) !== '' ? trim($label) : null,
                ...($mayConfirmReceipts === null ? [] : ['may_confirm_receipts' => $mayConfirmReceipts]),
            ],
        );
    }

    public function removeDiscordUser(AssistantDiscordUser $user): void
    {
        $user->delete();
    }

    public function issueToken(): string
    {
        return DB::transaction(function (): string {
            $settings = $this->settings();
            $bot = $settings->botUser ?? $this->createBotUser($settings);

            if ($bot->role !== $settings->bot_role) {
                $bot->update(['role' => $settings->bot_role]);
            }

            $bot->tokens()->delete();
            $settings->update(['bot_user_id' => $bot->id]);

            return $bot->createToken(self::TOKEN_NAME, [self::ABILITY])->plainTextToken;
        });
    }

    public function isBotToken(PersonalAccessToken $token): bool
    {
        $botId = $this->settings()->bot_user_id;

        return $botId !== null
            && (string) $token->tokenable_id === (string) $botId
            && in_array(self::ABILITY, (array) $token->abilities, true);
    }

    /** @param array<string, mixed> $status */
    public function reportStatus(array $status): void
    {
        Cache::put($this->statusKey(), [...$status, 'reported_at' => now()->toIso8601String()], now()->addMinutes(10));
    }

    /** @return array<string, mixed>|null */
    public function status(): ?array
    {
        $status = Cache::get($this->statusKey());

        return is_array($status) ? $status : null;
    }

    private function statusKey(): string
    {
        return 'assistant-status:'.$this->tenant->id();
    }

    public function revokeToken(): void
    {
        $this->settings()->botUser?->tokens()->delete();
    }

    /** @return array<string, mixed> */
    public function automationSettings(): array
    {
        $settings = $this->settings();
        $paired = $settings->botUser?->tokens()->where('name', self::TOKEN_NAME)->exists() ?? false;

        return [
            ...$this->discordConfiguration($settings),
            'paired' => $paired,
            'chat_configured' => $settings->llm_api_key !== null,
            'discord_status' => $paired ? $this->status() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function discordConfiguration(AssistantSetting $settings): array
    {
        return [
            'enabled' => $settings->enabled,
            ...$settings->only(AssistantSetting::CHANNEL_FIELDS),
            'discord_payment_alert_pesos' => $settings->discord_payment_alert_pesos,
        ];
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $settings = $this->settings()->load('botUser');
        $token = $settings->botUser?->tokens()->where('name', self::TOKEN_NAME)->latest()->first();

        return [
            ...$this->discordConfiguration($settings),
            'provider' => $settings->llm_provider,
            'model' => $settings->llm_model,
            'model_in_use' => $settings->modelName(),
            'has_key' => $settings->llm_api_key !== null,
            'key_hint' => $settings->llm_key_hint,
            'thinking' => $settings->llm_thinking,
            'has_discord_token' => $settings->discord_token !== null,
            'discord_token_hint' => $settings->discord_token_hint,
            'bot_role' => $settings->bot_role,
            'providers' => collect(AssistantSetting::PROVIDERS)
                ->map(static fn (array $p, string $key): array => ['value' => $key, 'label' => $p['label'], 'default_model' => $p['model']])
                ->values()
                ->all(),
            'bot_roles' => AssistantSetting::BOT_ROLES,
            'discord_status' => $token === null ? null : $this->status(),
            'token' => [
                'issued' => $token !== null,
                'created_at' => $token?->created_at?->toIso8601String(),
                'last_used_at' => $token?->last_used_at?->toIso8601String(),
            ],
            'discord_users' => AssistantDiscordUser::query()
                ->orderBy('label')
                ->orderBy('discord_id')
                ->get()
                ->map(static fn (AssistantDiscordUser $u): array => [
                    'id' => $u->id,
                    'discord_id' => $u->discord_id,
                    'label' => $u->label,
                    'may_confirm_receipts' => $u->may_confirm_receipts,
                    'added_at' => $u->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function botConfig(): array
    {
        $settings = $this->settings();

        return [
            'enabled' => $settings->enabled && $settings->llm_api_key !== null,
            'company' => $this->tenant->company()->name,
            'company_id' => $this->tenant->id(),
            'provider' => $settings->llm_provider,
            'model' => $settings->modelName(),
            'api_key' => $settings->llm_api_key,
            'thinking' => $settings->llm_thinking,
            'discord_token' => $settings->discord_token,
            ...$settings->only(AssistantSetting::CHANNEL_FIELDS),
            'finance_reports_enabled' => $settings->enabled && $settings->discord_finance_channel_id !== null,
            'hr_alerts_enabled' => $settings->enabled && $settings->discord_hr_channel_id !== null,
            'discord_ids' => AssistantDiscordUser::query()->pluck('discord_id')->values()->all(),
        ];
    }

    private function createBotUser(AssistantSetting $settings): User
    {
        $company = $this->tenant->company();

        return User::create([
            'name' => 'Discord Assistant',
            'email' => 'assistant.'.Str::lower((string) $company->id).'@assistant.cargorush.invalid',
            'password' => Str::random(64),
            'role' => $settings->bot_role,
        ]);
    }
}
