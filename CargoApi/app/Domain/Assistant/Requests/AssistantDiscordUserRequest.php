<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;

class AssistantDiscordUserRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['discord_id' => preg_replace('/\D+/', '', (string) $this->input('discord_id'))]);
    }

    public function rules(): array
    {
        return [
            'discord_id' => ['required', 'string', 'regex:/^\d{17,20}$/'],
            'label' => ['nullable', 'string', 'max:80'],
            'may_confirm_receipts' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['discord_id.regex' => 'A Discord user ID is 17 to 20 digits. In Discord, turn on Developer Mode, then right-click the person and choose Copy User ID.'];
    }
}
