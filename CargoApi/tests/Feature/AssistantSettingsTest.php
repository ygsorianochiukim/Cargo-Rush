<?php

declare(strict_types=1);

use App\Domain\Assistant\Models\AssistantSetting;
use App\Domain\Assistant\Services\AssistantService;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessService;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use App\Domain\Trip\Models\Trip;
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

it('validates distinct private channel destinations and returns them to the paired bot', function (): void {
    $role = app(AccessService::class)->createRole([
        'name' => 'Developer',
        'permissions' => ['automation.manage'],
    ]);
    $developer = User::create([
        'name' => 'Developer', 'email' => 'developer@test.test',
        'password' => 'password', 'role' => $role->key,
    ]);
    $this->actingAs($developer)->putJson('/api/v1/automation/bots/discord', ['discord_assistant_channel_id' => '#assistant'])
        ->assertUnprocessable()->assertJsonValidationErrors('discord_assistant_channel_id');
    $this->putJson('/api/v1/automation/bots/discord', [
        'enabled' => true,
        'discord_assistant_channel_id' => '111111111111111111',
        'discord_finance_channel_id' => '222222222222222222',
    ])->assertOk();
    $this->putJson('/api/v1/automation/bots/discord', ['discord_finance_alerts_channel_id' => '222222222222222222'])
        ->assertUnprocessable();

    $token = $this->actingAs($this->admin)->postJson('/api/v1/assistant/token')->json('meta.token');
    $this->app['auth']->forgetGuards();
    ($this->botConfig)($token)->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.finance_reports_enabled', true)
        ->assertJsonPath('data.discord_finance_channel_id', '222222222222222222');
});

it('reports tenant-scoped finance activity by local calendar day and filters important alerts', function (): void {
    $this->travelTo(now()->setDate(2026, 11, 20)->setTime(16, 30));
    app(AssistantService::class)->update([
        'enabled' => true,
        'discord_finance_channel_id' => '222222222222222222',
        'discord_payment_alert_pesos' => 500,
    ]);
    $makePayment = fn (int $amount): Payment => Payment::create([
        'direction' => 'receivable',
        'amount_cents' => $amount,
        'currency' => 'PHP',
        'paid_on' => now()->subDay(),
        'method' => 'cash',
    ]);
    $small = $makePayment(10000);
    $large = $makePayment(50000);
    $other = $this->makeCompany('Other Finance Company');
    $foreign = $this->asCompany($other, fn (): Payment => $makePayment(60000));
    $invoice = Invoice::create([
        'direction' => 'payable', 'status' => 'partial', 'amount_cents' => 100000,
        'currency' => 'PHP', 'issued_at' => now()->subWeek(), 'due_at' => now()->subDay(),
    ]);
    $this->artisan('cargo:invoices-overdue')->assertSuccessful();

    $path = '/api/v1/assistant/finance-activity?day=2026-11-21&timezone=Asia%2FManila';
    $this->actingAs($this->admin)->getJson($path.'&kind=payments')->assertForbidden();
    $token = $this->postJson('/api/v1/assistant/token')->assertOk()->json('meta.token');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson($path.'&kind=payments')->assertOk()
        ->assertJsonCount(2, 'data.events')->assertJsonFragment(['id' => $small->id])
        ->assertJsonFragment(['id' => $large->id])->assertJsonMissing(['id' => $foreign->id]);
    $this->getJson($path.'&kind=payments&alerts=1')->assertOk()
        ->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.id', $large->id);
    $this->getJson(str_replace('2026-11-21', '2026-11-20', $path).'&kind=payments')->assertOk()
        ->assertJsonCount(0, 'data.events');
    $this->getJson($path.'&kind=runs&alerts=1')->assertOk()
        ->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.changed_count', 1);
    $this->getJson($path.'&kind=changes')->assertOk()
        ->assertJsonPath('data.events.0.invoice_id', $invoice->id)
        ->assertJsonPath('data.events.0.new_status', 'overdue');
});

it('queues one Discord test alert for the current company and accepts delivery only from its paired bot', function (): void {
    $this->admin->forceFill(['developer_access' => true])->save();
    $assistant = app(AssistantService::class);
    $assistant->update([
        'enabled' => true,
        'discord_finance_channel_id' => '111111111111111111',
        'discord_finance_alerts_channel_id' => '222222222222222222',
        'discord_hr_channel_id' => '333333333333333333',
    ]);
    $token = $assistant->issueToken();
    $path = '/api/v1/automation/bots/discord';
    $botPath = '/api/v1/assistant/test-alert';

    $this->actingAs($this->admin)->postJson($path.'/test-alert', ['target' => 'finance'])
        ->assertAccepted()->assertJsonPath('data.channel_id', '222222222222222222');
    $this->postJson($path.'/test-alert', ['target' => 'hr'])->assertConflict();
    $this->getJson($botPath)->assertForbidden();
    $this->app['auth']->forgetGuards();

    $test = $this->withToken($token)->getJson($botPath)->assertOk()
        ->assertJsonPath('data.test.status', 'queued')->json('data.test');
    $this->postJson($botPath.'/'.$test['id'], [
        'status' => 'delivered', 'guild_id' => '444444444444444444', 'message_id' => '555555555555555555',
    ])->assertNoContent();
    $this->getJson($botPath)->assertOk()->assertJsonPath('data.test', null);
    $this->app['auth']->forgetGuards();

    $this->actingAs($this->admin)->getJson($path)->assertOk()
        ->assertJsonPath('data.test_alert.status', 'delivered')
        ->assertJsonPath('data.test_alert.message_url', 'https://discord.com/channels/444444444444444444/222222222222222222/555555555555555555');
    $this->postJson($path.'/test-alert', ['target' => 'hr'])->assertAccepted()
        ->assertJsonPath('data.channel_id', '333333333333333333');
    $this->travel(6)->minutes();
    $this->getJson($path)->assertOk()->assertJsonPath('data.test_alert.status', 'expired');
});

it('replaces five-day alerts with tenant-scoped billing and payment reminders on the Manila cutoff schedule', function (): void {
    $assistant = app(AssistantService::class);
    $assistant->update(['enabled' => true, 'discord_finance_channel_id' => '222222222222222222']);
    $createInvoice = static function (array $fields = [], string $delivery = '2026-11-05 16:30:00'): Invoice {
        $trip = Trip::create([
            'origin' => 'Bacolod', 'destination' => 'Iloilo', 'cargo' => 'Produce', 'weight_kg' => 100,
            'scheduled_at' => '2026-11-05 15:30:00', 'status' => 'delivered',
        ]);
        DeliveryLog::create(['trip_id' => $trip->id, 'delivered_at' => $delivery, 'status' => 'delivered']);

        return Invoice::create([
            'trip_id' => $trip->id, 'direction' => 'receivable', 'status' => 'pending', 'amount_cents' => 100_000,
            'currency' => 'PHP', 'issued_at' => '2026-11-06', 'due_at' => '2026-12-10', ...$fields,
        ]);
    };
    $createInvoice();
    $billed = $createInvoice(['billed_at' => '2026-11-17', 'status' => 'partial']);
    $paid = Payment::create([
        'direction' => 'receivable', 'amount_cents' => 25_000, 'currency' => 'PHP', 'paid_on' => '2026-11-20',
    ]);
    $paid->allocations()->create(['invoice_id' => $billed->id, 'amount_cents' => 25_000]);
    $createInvoice(['status' => 'paid']);
    $createInvoice(['status' => 'cancelled']);
    $createInvoice(['direction' => 'payable']);
    $createInvoice(['trip_id' => null]);
    $createInvoice([], '2026-11-05 15:59:59');
    $createInvoice([], '2026-11-30 16:00:00');
    $this->asCompany($this->makeCompany('Other Receivables Company'), $createInvoice);

    $this->withToken($assistant->issueToken());
    $activity = fn (string $day, string $kind = 'cutoff-reminders') => $this
        ->getJson('/api/v1/assistant/finance-activity?'.http_build_query(compact('kind', 'day') + ['timezone' => 'UTC']))
        ->assertOk();

    $activity('2026-11-16')->assertJsonCount(0, 'data.events');
    foreach (['2026-11-17', '2026-11-18'] as $day) {
        $activity($day)
            ->assertJsonCount(1, 'data.events')
            ->assertJsonPath('data.events.0.id', 'billing:2026-11-06')
            ->assertJsonPath('data.events.0.billing_to', '2026-11-18')
            ->assertJsonPath('data.events.0.invoice_count', 1)
            ->assertJsonPath('data.events.0.balances.0.balance_cents', 100_000);
    }
    $activity('2026-11-21', 'aged-receivables')->assertJsonCount(0, 'data.events');
    $activity('2026-11-28')
        ->assertJsonCount(1, 'data.events')
        ->assertJsonPath('data.events.0.id', 'payment:2026-11-06')
        ->assertJsonPath('data.events.0.payment_target', '2026-11-28')
        ->assertJsonPath('data.events.0.invoice_count', 1)
        ->assertJsonPath('data.events.0.balances.0.balance_cents', 75_000);
    $activity('2026-12-07')
        ->assertJsonCount(1, 'data.events')
        ->assertJsonPath('data.events.0.id', 'billing:2026-11-26')
        ->assertJsonPath('data.events.0.end', '2026-12-05')
        ->assertJsonPath('data.events.0.payment_target', '2026-12-18');
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
