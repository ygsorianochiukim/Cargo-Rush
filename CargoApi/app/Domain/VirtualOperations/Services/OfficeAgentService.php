<?php

declare(strict_types=1);

namespace App\Domain\VirtualOperations\Services;

use App\Domain\Assistant\Models\AssistantSetting;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class OfficeAgentService
{
    public const DESKS = ['dispatch', 'fleet', 'office'];

    private const ENDPOINTS = [
        'openai' => 'https://api.openai.com/v1/chat/completions',
        'deepseek' => 'https://api.deepseek.com/chat/completions',
    ];

    public function __construct(private readonly VirtualOperationsService $operations) {}

    public function answer(Company $company, string $desk, string $question): string
    {
        $settings = AssistantSetting::query()->first();
        $provider = $settings?->llm_provider;
        $endpoint = self::ENDPOINTS[$provider] ?? null;
        if ($endpoint === null || ! $settings->enabled || blank($settings->llm_api_key)) {
            throw ValidationException::withMessages(['question' => 'Enable the AI assistant and save an API key in Assistant Settings.']);
        }

        $snapshot = $this->operations->snapshot($company);
        $statuses = array_count_values(array_column($snapshot['yard']['fleet'], 'fleet_status'));
        $fleet = [
            'parked' => count($snapshot['yard']['parked']),
            'traveling' => $snapshot['yard']['traveling'],
            'active_in_yard' => $statuses['active'] ?? 0,
            'available' => $statuses['available'] ?? 0,
            'unavailable' => $statuses['unavailable'] ?? 0,
        ];
        $context = match ($desk) {
            'dispatch' => ['dispatch' => $snapshot['dispatch']],
            'fleet' => ['fleet' => $fleet],
            'office' => [
                'active_employees' => count($snapshot['employees']),
                'virtual_areas' => count($snapshot['rooms']),
                'dispatch' => $snapshot['dispatch'],
                'fleet' => $fleet,
            ],
        };

        try {
            $response = Http::withToken($settings->llm_api_key)->acceptJson()->connectTimeout(5)->timeout(25)
                ->post($endpoint, [
                    'model' => $settings->modelName(),
                    ($provider === 'openai' ? 'max_completion_tokens' : 'max_tokens') => 700,
                    ...($provider === 'deepseek' ? ['thinking' => ['type' => 'disabled']] : []),
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a read-only '.$desk.' agent for a logistics office. Answer briefly in plain text. Use only the supplied snapshot for claims about the company. If the data is missing or the question is outside your role, say so. You cannot change records, dispatch trucks, or contact anyone.'],
                        ['role' => 'user', 'content' => json_encode(['snapshot' => $context, 'question' => $question], JSON_THROW_ON_ERROR)],
                    ],
                ]);
        } catch (ConnectionException) {
            abort(502, 'The AI provider could not be reached. Try again.');
        }

        abort_unless($response->successful(), 502, 'The AI provider rejected the request. Check Assistant Settings.');
        $answer = $response->json('choices.0.message.content');
        abort_unless(is_string($answer) && filled($answer), 502, 'The AI provider did not return an answer. Try again.');

        return trim($answer);
    }
}
