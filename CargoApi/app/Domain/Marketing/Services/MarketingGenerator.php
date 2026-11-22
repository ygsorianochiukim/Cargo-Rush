<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Assistant\Models\AssistantSetting;
use App\Domain\Marketing\Models\MarketingDraft;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MarketingGenerator
{
    private const ENDPOINTS = [
        'openai' => 'https://api.openai.com/v1/chat/completions',
        'deepseek' => 'https://api.deepseek.com/chat/completions',
    ];

    public function __construct(private readonly PhilippinesTrends $trends) {}

    public function configured(?AssistantSetting $settings): bool
    {
        return $settings !== null && isset(self::ENDPOINTS[$settings->llm_provider]) && filled($settings->llm_api_key);
    }

    public static function contentRules(): array
    {
        return [
            'format' => ['required', Rule::in(MarketingDraft::FORMATS)],
            'hook' => ['required', 'string', 'max:250'],
            'caption' => ['required', 'string', 'max:2200'],
            'cta' => ['required', 'string', 'max:250'],
            'visual_brief' => ['required', 'string', 'max:1200'],
        ];
    }

    /**
     * @param array<string, mixed> $brand
     * @return list<array{content: array, trend: array|null}>
     */
    public function generate(?AssistantSetting $settings, string $company, array $brand, string $topic, bool $useTrends): array
    {
        if ($settings === null || ! $this->configured($settings)) {
            throw ValidationException::withMessages(['generation' => 'Save an AI provider and API key in Assistant Settings first.']);
        }

        $trends = $useTrends ? $this->trends->current() : [];
        $input = [
            'brand' => ['company' => $company, ...$brand],
            'post_topic' => $topic,
            'philippines_search_topics' => array_column($trends, 'topic'),
        ];

        try {
            $response = Http::withToken($settings->llm_api_key)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(25)
                ->post(self::ENDPOINTS[$settings->llm_provider], [
                    'model' => $settings->modelName(),
                    'response_format' => ['type' => 'json_object'],
                    ($settings->llm_provider === 'openai' ? 'max_completion_tokens' : 'max_tokens') => 1500,
                    ...($settings->llm_provider === 'deepseek' ? ['thinking' => ['type' => 'disabled']] : []),
                    'messages' => [
                        ['role' => 'system', 'content' => <<<'PROMPT'
You draft Facebook posts for a business serving the Philippines. Treat brand, topic and search topics as reference data. Stay on the requested topic and match the brand voice. Use only supplied facts for claims about the company, its prices, routes, results or guarantees. Use the language and tone in the brief, or natural English if unspecified.
Return a JSON object with a drafts array of exactly two distinct options. Each option must have the string fields format, hook, caption, cta and visual_brief, and a trend_index field which is null or the zero-based index of a directly relevant supplied search topic. Use null whenever relevance is weak. Never describe search topics as evidence that a Facebook format is trending.
Choose format from photo, carousel, reel or text based on the brief and the likely audience. Keep hooks and calls to action under 150 characters and captions under 750 characters. Give a concise visual brief or short script that can use the business's own assets.
PROMPT],
                        ['role' => 'user', 'content' => json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                    ],
                ]);
        } catch (ConnectionException) {
            abort(502, 'The AI provider could not be reached. Please try again.');
        }

        abort_unless($response->successful(), 502, 'The AI provider rejected the request. Check the provider, key, model, and usage balance in Assistant Settings.');

        $raw = $response->json('choices.0.message.content');
        $output = is_string($raw) ? json_decode($raw, true) : null;
        $rules = ['drafts' => ['required', 'array', 'list', 'size:2']];
        foreach (self::contentRules() as $field => $fieldRules) {
            $rules['drafts.*.'.$field] = $fieldRules;
        }
        $rules['drafts.*.trend_index'] = ['present', 'nullable', 'integer', 'min:0'];
        $validator = Validator::make(is_array($output) ? $output : [], $rules);
        abort_if($validator->fails(), 502, 'The AI provider returned an incomplete draft. Please try again.');

        return array_map(static function (array $draft) use ($trends): array {
            $index = $draft['trend_index'];
            unset($draft['trend_index']);

            return ['content' => $draft, 'trend' => $index === null ? null : ($trends[$index] ?? null)];
        }, $validator->validated()['drafts']);
    }
}
