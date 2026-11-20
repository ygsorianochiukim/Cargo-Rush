<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Requests;

use App\Domain\Assistant\Models\AssistantSetting;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class AssistantSettingsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'llm_provider' => ['sometimes', Rule::in(array_keys(AssistantSetting::PROVIDERS))],
            'llm_model' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'llm_thinking' => ['sometimes', 'boolean'],
            'bot_role' => ['sometimes', Rule::in(AssistantSetting::BOT_ROLES)],
            'api_key' => ['sometimes', 'nullable', 'string', 'min:8', 'max:500'],
            'clear_key' => ['sometimes', 'boolean'],
            'discord_token' => ['sometimes', 'nullable', 'string', 'min:50', 'max:200', 'regex:/^[A-Za-z0-9._-]+$/'],
            'clear_discord_token' => ['sometimes', 'boolean'],
            'discord_assistant_channel_id' => ['sometimes', 'nullable', 'string', 'regex:/^[1-9][0-9]{16,19}$/'],
            'discord_finance_channel_id' => ['sometimes', 'nullable', 'string', 'regex:/^[1-9][0-9]{16,19}$/'],
            'discord_finance_alerts_channel_id' => ['sometimes', 'nullable', 'string', 'regex:/^[1-9][0-9]{16,19}$/'],
            'discord_hr_channel_id' => ['sometimes', 'nullable', 'string', 'regex:/^[1-9][0-9]{16,19}$/'],
            'discord_payment_alert_pesos' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'llm_model.regex' => 'A model name has only letters, numbers, dots, dashes and slashes.',
            'discord_token.min' => 'That does not look like a Discord bot token. Copy it from the Developer Portal → Bot → Reset Token.',
            'discord_token.regex' => 'That does not look like a Discord bot token. Copy it from the Developer Portal → Bot → Reset Token.',
        ];
    }

    /** @return array<string, mixed> */
    public function toInput(): array
    {
        return $this->validated();
    }
}
