<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Services;

use App\Domain\Assistant\Models\AssistantDiscordUser;
use App\Domain\Assistant\Models\AssistantSetting;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $settings = $this->settings();

        $settings->fill(array_intersect_key($input, array_flip(['enabled', 'llm_provider', 'llm_model', 'llm_thinking', 'bot_role'])));

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

        DB::transaction(function () use ($settings): void {
            $settings->save();

            if ($settings->wasChanged('bot_role')) {
                $settings->botUser?->update(['role' => $settings->bot_role]);
            }
        });

        return $settings->refresh();
    }

    public function addDiscordUser(string $discordId, ?string $label): AssistantDiscordUser
    {
        return AssistantDiscordUser::query()->updateOrCreate(
            ['discord_id' => $discordId],
            ['label' => $label !== null && trim($label) !== '' ? trim($label) : null],
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

    public function revokeToken(): void
    {
        $this->settings()->botUser?->tokens()->delete();
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $settings = $this->settings()->load('botUser');
        $token = $settings->botUser?->tokens()->where('name', self::TOKEN_NAME)->latest()->first();

        return [
            'enabled' => $settings->enabled,
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
            'provider' => $settings->llm_provider,
            'model' => $settings->modelName(),
            'api_key' => $settings->llm_api_key,
            'thinking' => $settings->llm_thinking,
            'discord_token' => $settings->discord_token,
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
