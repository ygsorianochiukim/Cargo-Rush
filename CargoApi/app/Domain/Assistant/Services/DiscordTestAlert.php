<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Services;

use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DiscordTestAlert
{
    public function __construct(private readonly Tenant $tenant, private readonly AssistantService $assistant) {}

    public function latest(): ?array
    {
        $test = Cache::get($this->key());
        if ($test !== null && $test['status'] === 'queued' && $test['expires_at'] <= now()->timestamp) {
            $test['status'] = 'expired';
        }

        return $test;
    }

    public function queue(string $target): array
    {
        $channel = $this->channel($target);
        abort_if($channel === null, 422, 'Enable the bot and save a channel for this alert.');
        abort_unless($this->assistant->automationSettings()['paired'], 422, 'Pair the Discord bot before sending a test.');

        return Cache::lock($this->key().':lock', 10)->block(3, function () use ($target, $channel): array {
            abort_if(($this->latest()['status'] ?? null) === 'queued', 409, 'A Discord test alert is already queued.');
            $test = [
                'id' => (string) Str::ulid(),
                'target' => $target,
                'channel_id' => $channel,
                'status' => 'queued',
                'expires_at' => now()->addMinutes(5)->timestamp,
                'message_url' => null,
            ];
            Cache::put($this->key(), $test, now()->addDay());

            return $test;
        });
    }

    public function pending(): ?array
    {
        $test = $this->latest();
        if (($test['status'] ?? null) !== 'queued') {
            return null;
        }
        if ($this->channel($test['target']) !== $test['channel_id']) {
            $this->finish($test['id'], 'failed');

            return null;
        }

        return $test;
    }

    public function finish(string $id, string $status, ?string $guild = null, ?string $message = null): void
    {
        Cache::lock($this->key().':lock', 10)->block(3, function () use ($id, $status, $guild, $message): void {
            $test = Cache::get($this->key());
            if (($test['id'] ?? null) !== $id || $test['status'] !== 'queued') {
                return;
            }
            $test['status'] = $status;
            if ($status === 'delivered') {
                $test['message_url'] = "https://discord.com/channels/{$guild}/{$test['channel_id']}/{$message}";
            }
            Cache::put($this->key(), $test, now()->addDay());
        });
    }

    private function channel(string $target): ?string
    {
        $settings = $this->assistant->settings();
        if (! $settings->enabled) {
            return null;
        }

        return match ($target) {
            'hr' => $settings->discord_hr_channel_id,
            'finance' => $settings->discord_finance_alerts_channel_id ?? $settings->discord_finance_channel_id,
        };
    }

    private function key(): string
    {
        return 'assistant-test-alert:'.$this->tenant->id();
    }
}
