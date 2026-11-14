<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantSetting extends Model
{
    use BelongsToCompany, HasUlids;

    public const PROVIDERS = [
        'deepseek' => ['label' => 'DeepSeek', 'model' => 'deepseek-v4-pro'],
        'openai' => ['label' => 'OpenAI', 'model' => 'gpt-4.1'],
    ];

    public const BOT_ROLES = ['accountant', 'administrator'];

    protected $fillable = [
        'enabled', 'llm_provider', 'llm_model', 'llm_api_key', 'llm_key_hint', 'llm_thinking',
        'discord_token', 'discord_token_hint', 'bot_role', 'bot_user_id',
    ];

    protected $hidden = ['llm_api_key', 'discord_token'];

    protected $attributes = [
        'enabled' => false,
        'llm_provider' => 'deepseek',
        'llm_thinking' => false,
        'bot_role' => 'accountant',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'llm_thinking' => 'boolean',
            'llm_api_key' => 'encrypted',
            'discord_token' => 'encrypted',
        ];
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bot_user_id');
    }

    public function modelName(): string
    {
        return $this->llm_model ?: self::PROVIDERS[$this->llm_provider]['model'] ?? self::PROVIDERS['deepseek']['model'];
    }
}
