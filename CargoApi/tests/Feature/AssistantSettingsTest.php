<?php

declare(strict_types=1);

use App\Domain\Assistant\Models\AssistantSetting;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]);

    $this->settings = fn (array $payload) => $this->actingAs($this->admin)
        ->putJson('/api/v1/assistant/settings', $payload);

    $this->botConfig = fn (string $token) => $this->withToken($token)->getJson('/api/v1/assistant/bot-config');
});

it('keeps the AI key encrypted, never sends it back, and keeps it out of the change log', function (): void {
    $key = 'sk-deepseek-1234567890abcd';

    $page = ($this->settings)(['api_key' => $key, 'enabled' => true, 'llm_provider' => 'deepseek'])
        ->assertOk()
        ->json('data');

    expect($page['has_key'])->toBeTrue()
        ->and($page['key_hint'])->toBe('abcd')
        ->and($page['model_in_use'])->toBe('deepseek-v4-pro')
        ->and(json_encode($page))->not->toContain($key);

    $raw = DB::table('assistant_settings')->value('llm_api_key');

    expect($raw)->not->toBe($key)
        ->and($raw)->not->toContain('1234567890')
        ->and(Crypt::decryptString($raw))->toBe($key);

    expect(DB::table('assistant_settings_logs')->pluck('new_values')->implode(' '))
        ->not->toContain('llm_api_key')
        ->not->toContain($key);
});

it('keeps the saved key when the box is left blank, and clears it on request', function (): void {
    ($this->settings)(['api_key' => 'sk-first-key-0001'])->assertOk();

    ($this->settings)(['api_key' => '', 'llm_thinking' => true])
        ->assertOk()
        ->assertJsonPath('data.key_hint', '0001')
        ->assertJsonPath('data.thinking', true);

    ($this->settings)(['clear_key' => true])
        ->assertOk()
        ->assertJsonPath('data.has_key', false)
        ->assertJsonPath('data.key_hint', null);

    expect(AssistantSetting::query()->value('llm_api_key'))->toBeNull();
});

it('accepts Discord user IDs, cleans them up, and refuses ones that are not IDs', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/assistant/discord-users', ['discord_id' => ' 1571205865602687158 ', 'label' => 'Yvert'])
        ->assertOk()
        ->assertJsonPath('data.discord_users.0.discord_id', '1571205865602687158')
        ->assertJsonPath('data.discord_users.0.label', 'Yvert');

    $this->actingAs($this->admin)
        ->postJson('/api/v1/assistant/discord-users', ['discord_id' => '1571205865602687158', 'label' => 'Yvert S.'])
        ->assertOk()
        ->assertJsonCount(1, 'data.discord_users')
        ->assertJsonPath('data.discord_users.0.label', 'Yvert S.');

    $this->actingAs($this->admin)
        ->postJson('/api/v1/assistant/discord-users', ['discord_id' => 'yvert#1234'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('discord_id');

    $id = $this->actingAs($this->admin)->getJson('/api/v1/assistant/settings')->json('data.discord_users.0.id');

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/assistant/discord-users/{$id}")
        ->assertOk()
        ->assertJsonCount(0, 'data.discord_users');
});

it('gives the bot a token shown once, hashed at rest, that reads its settings', function (): void {
    ($this->settings)(['api_key' => 'sk-live-key-9999', 'enabled' => true, 'llm_provider' => 'openai', 'llm_model' => 'gpt-test'])->assertOk();
    $this->actingAs($this->admin)->postJson('/api/v1/assistant/discord-users', ['discord_id' => '111111111111111111'])->assertOk();

    $issued = $this->actingAs($this->admin)->postJson('/api/v1/assistant/token')->assertOk();
    $token = $issued->json('meta.token');

    expect($token)->toBeString()
        ->and($issued->json('data.token.issued'))->toBeTrue()
        ->and($this->actingAs($this->admin)->getJson('/api/v1/assistant/settings')->json('meta'))->toBeNull();

    [, $secret] = explode('|', $token, 2);
    expect(DB::table('personal_access_tokens')->value('token'))->toBe(hash('sha256', $secret));

    $this->app['auth']->forgetGuards();

    ($this->botConfig)($token)
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.provider', 'openai')
        ->assertJsonPath('data.model', 'gpt-test')
        ->assertJsonPath('data.api_key', 'sk-live-key-9999')
        ->assertJsonPath('data.discord_ids', ['111111111111111111']);
});

it('reads company data with the bot token as an accountant, but not admin pages', function (): void {
    $token = $this->actingAs($this->admin)->postJson('/api/v1/assistant/token')->json('meta.token');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/finance/profitability')->assertOk();
    $this->withToken($token)->getJson('/api/v1/assistant/settings')->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/company')->assertForbidden();
});

it('turns the old token off when a new one is issued or it is revoked', function (): void {
    $first = $this->actingAs($this->admin)->postJson('/api/v1/assistant/token')->json('meta.token');
    $second = $this->actingAs($this->admin)->postJson('/api/v1/assistant/token')->json('meta.token');
    $this->app['auth']->forgetGuards();

    ($this->botConfig)($first)->assertUnauthorized();
    ($this->botConfig)($second)->assertOk();

    $this->actingAs($this->admin)->deleteJson('/api/v1/assistant/token')->assertOk()->assertJsonPath('data.token.issued', false);
    $this->app['auth']->forgetGuards();

    ($this->botConfig)($second)->assertUnauthorized();
});

it('stays switched off until there is a key', function (): void {
    ($this->settings)(['enabled' => true])->assertOk();
    $token = $this->actingAs($this->admin)->postJson('/api/v1/assistant/token')->json('meta.token');
    $this->app['auth']->forgetGuards();

    ($this->botConfig)($token)->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.api_key', null);
});

it('never hands the key to anyone but the bot token', function (): void {
    ($this->settings)(['api_key' => 'sk-secret-key-7777'])->assertOk();

    $this->actingAs($this->admin)->getJson('/api/v1/assistant/bot-config')->assertForbidden();

    $adminToken = $this->postJson('/api/v1/login', [
        'email' => 'owner@test.test', 'password' => 'password', 'device_name' => 'phone',
    ])->json('meta.token');
    $this->app['auth']->forgetGuards();

    ($this->botConfig)($adminToken)->assertForbidden();

    $dispatcher = User::create(['name' => 'Desk', 'email' => 'desk@test.test', 'password' => 'password', 'role' => 'dispatcher']);
    $this->actingAs($dispatcher)->getJson('/api/v1/assistant/settings')->assertForbidden();
    $this->actingAs($dispatcher)->putJson('/api/v1/assistant/settings', ['api_key' => 'sk-hijack-0000'])->assertForbidden();
});

describe('pairing from the settings page', function (): void {
    beforeEach(function (): void {
        $this->pair = fn () => $this->postJson('/api/v1/assistant/pairings')->assertCreated()->json('data');
        $this->poll = fn (array $p, ?string $secret = null) => $this->withHeader('X-Pairing-Secret', $secret ?? $p['secret'])
            ->getJson("/api/v1/assistant/pairings/{$p['id']}");
    });

    it('lets a bot ask to connect, shows the code on the page, and hands the token over once on approval', function (): void {
        $request = ($this->pair)();

        expect($request['code'])->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/');

        $page = $this->actingAs($this->admin)->getJson('/api/v1/assistant/settings')->json('data');
        expect($page['pairings'])->toHaveCount(1)
            ->and($page['pairings'][0]['code'])->toBe($request['code'])
            ->and(json_encode($page))->not->toContain($request['secret']);

        ($this->poll)($request)->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('data.token');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/assistant/pairings/{$page['pairings'][0]['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.token.issued', true)
            ->assertJsonCount(0, 'data.pairings');

        $this->app['auth']->forgetGuards();

        $token = ($this->poll)($request)->assertOk()->assertJsonPath('data.status', 'approved')->json('data.token');
        ($this->poll)($request)->assertOk()->assertJsonPath('data.status', 'claimed')->assertJsonMissingPath('data.token');

        expect(DB::table('assistant_pairings')->value('token'))->toBeNull();

        ($this->botConfig)($token)->assertOk();
    });

    it('answers nobody who does not hold the bot\'s secret', function (): void {
        $request = ($this->pair)();

        ($this->poll)($request, 'guess')->assertNotFound();
    });

    it('lets a code run out, and refuses to approve it afterwards', function (): void {
        $request = ($this->pair)();
        $id = $this->actingAs($this->admin)->getJson('/api/v1/assistant/settings')->json('data.pairings.0.id');

        $this->travel(11)->minutes();

        ($this->poll)($request)->assertOk()->assertJsonPath('data.status', 'expired');
        $this->actingAs($this->admin)->getJson('/api/v1/assistant/settings')->assertJsonCount(0, 'data.pairings');
        $this->actingAs($this->admin)->postJson("/api/v1/assistant/pairings/{$id}/approve")->assertStatus(410);
    });

    it('can dismiss a request that was not expected', function (): void {
        $request = ($this->pair)();
        $id = $this->actingAs($this->admin)->getJson('/api/v1/assistant/settings')->json('data.pairings.0.id');

        $this->actingAs($this->admin)->deleteJson("/api/v1/assistant/pairings/{$id}")->assertOk()->assertJsonCount(0, 'data.pairings');
        $this->app['auth']->forgetGuards();

        ($this->poll)($request)->assertOk()->assertJsonPath('data.status', 'dismissed');
    });

    it('keeps strangers from flooding the page with requests', function (): void {
        foreach (range(1, 5) as $_) {
            ($this->pair)();
        }

        $this->postJson('/api/v1/assistant/pairings')->assertStatus(429);
    });

    it('lets only an administrator approve', function (): void {
        ($this->pair)();
        $id = $this->actingAs($this->admin)->getJson('/api/v1/assistant/settings')->json('data.pairings.0.id');

        $dispatcher = User::create(['name' => 'Desk', 'email' => 'desk2@test.test', 'password' => 'password', 'role' => 'dispatcher']);
        $this->actingAs($dispatcher)->postJson("/api/v1/assistant/pairings/{$id}/approve")->assertForbidden();
    });
});

describe('the Discord bot token', function (): void {
    it('is saved encrypted, shown only as a hint, and given to the bot', function (): void {
        $discord = 'MTU3MTIwNTg2NTYwMjY4NzE1OA.Gw071a.'.str_repeat('x', 34).'WXYZ';

        $page = ($this->settings)(['discord_token' => $discord, 'api_key' => 'sk-key-12345678'])->assertOk()->json('data');

        expect($page['has_discord_token'])->toBeTrue()
            ->and($page['discord_token_hint'])->toBe('WXYZ')
            ->and(json_encode($page))->not->toContain($discord)
            ->and(DB::table('assistant_settings')->value('discord_token'))->not->toContain('MTU3MTIw')
            ->and(DB::table('assistant_settings_logs')->pluck('new_values')->implode(' '))
            ->not->toContain('"discord_token":')
            ->not->toContain('MTU3MTIw');

        $token = $this->actingAs($this->admin)->postJson('/api/v1/assistant/token')->json('meta.token');
        $this->app['auth']->forgetGuards();

        ($this->botConfig)($token)->assertOk()->assertJsonPath('data.discord_token', $discord);
    });

    it('refuses something that is not a Discord token', function (): void {
        ($this->settings)(['discord_token' => 'hello'])->assertUnprocessable()->assertJsonValidationErrors('discord_token');
    });
});

describe('the bot reporting how Discord is going', function (): void {
    it('shows the bot\'s last Discord status on the page, and takes it only from the bot', function (): void {
        $token = $this->actingAs($this->admin)->postJson('/api/v1/assistant/token')->json('meta.token');

        $this->actingAs($this->admin)->getJson('/api/v1/assistant/settings')->assertJsonPath('data.discord_status', null);
        $this->actingAs($this->admin)->postJson('/api/v1/assistant/bot-status', ['state' => 'online'])->assertForbidden();

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson('/api/v1/assistant/bot-status', [
            'state' => 'online', 'tag' => 'Eisenlink Bot#0001', 'servers' => 0,
            'invite_url' => 'https://discord.com/oauth2/authorize?client_id=1571205865602687158&scope=bot',
        ])->assertNoContent();

        $this->withToken($token)->postJson('/api/v1/assistant/bot-status', ['state' => 'sleeping'])->assertUnprocessable();

        $this->app['auth']->forgetGuards();

        $this->actingAs($this->admin)->getJson('/api/v1/assistant/settings')
            ->assertJsonPath('data.discord_status.state', 'online')
            ->assertJsonPath('data.discord_status.tag', 'Eisenlink Bot#0001')
            ->assertJsonPath('data.discord_status.servers', 0);
    });
});
