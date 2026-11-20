<?php

declare(strict_types=1);

namespace App\Domain\Automation\Requests;

use App\Domain\Assistant\Models\AssistantSetting;
use App\Domain\Assistant\Requests\AssistantSettingsRequest;

class AutomationBotSettingsRequest extends AssistantSettingsRequest
{
    public function rules(): array
    {
        return array_intersect_key(parent::rules(), array_flip([
            'enabled', ...AssistantSetting::CHANNEL_FIELDS, 'discord_payment_alert_pesos',
        ]));
    }
}
