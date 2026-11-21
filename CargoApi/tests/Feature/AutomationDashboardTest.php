<?php

declare(strict_types=1);

use App\Domain\Assistant\Services\AssistantService;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Services\BillingService;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessService;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed([PermissionSeeder::class, NavigationSeeder::class]);
    $this->admin = User::create([
        'name' => 'Automation Admin',
        'email' => 'automation-admin@example.test',
        'password' => 'password',
        'role' => 'administrator',
    ]);
    $role = app(AccessService::class)->createRole([
        'name' => 'Developer',
        'permissions' => ['automation.manage'],
    ]);
    $this->developer = User::create([
        'name' => 'Developer',
        'email' => 'developer@example.test',
        'password' => 'password',
        'role' => $role->key,
    ]);
});

it('records scheduled and manual runs and links only the changes each run made', function (): void {
    $invoice = Invoice::create([
        'direction' => 'payable',
        'issued_at' => now()->subMonth(),
        'due_at' => now()->subDay(),
        'currency' => 'PHP',
        'amount_cents' => 1000,
        'status' => 'partial',
    ]);

    $this->artisan('cargo:invoices-overdue', ['--scheduled' => true])->assertSuccessful();
    $run = AutomationRun::query()->sole();

    $this->actingAs($this->developer)->getJson("/api/v1/automation/runs/{$run->id}")
        ->assertOk()
        ->assertJsonPath('data.run.trigger', 'scheduled')
        ->assertJsonPath('data.run.status', 'completed')
        ->assertJsonPath('data.run.changed_count', 1)
        ->assertJsonPath('data.changes.0.invoice_id', $invoice->id)
        ->assertJsonPath('data.changes.0.before', 'partial')
        ->assertJsonPath('data.changes.0.after', 'overdue');

    $invoice->refresh()->update(['status' => 'cancelled']);
    expect(DB::table('invoices_logs')->where('record_id', $invoice->id)
        ->whereNotNull('automation_run_id')->count())->toBe(1);

    $this->artisan('cargo:invoices-overdue')->assertSuccessful();

    $this->getJson('/api/v1/automation/runs')
        ->assertOk()
        ->assertJsonCount(0, 'data.active')
        ->assertJsonCount(2, 'data.history')
        ->assertJsonPath('data.latest_finance_run.trigger', 'manual')
        ->assertJsonPath('data.latest_finance_run.changed_count', 0);

    $this->getJson('/api/v1/automation/runs?search=scheduled&per_page=1')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.history.0.id', $run->id);
    $this->getJson('/api/v1/automation/runs?search=Overdue%20invoice%20check&per_page=1&page=2')
        ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.history.0.id', $run->id);
    $this->getJson("/api/v1/automation/runs/{$run->id}?search=overdue")
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.changes.0.invoice_id', $invoice->id);
    $this->getJson("/api/v1/automation/runs/{$run->id}?search=missing")
        ->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data.changes');
});

it('keeps automation history company scoped and restricts it to authorized users', function (): void {
    $other = $this->makeCompany('Other Automation Company');
    $foreignRun = $this->asCompany($other, fn (): AutomationRun => AutomationRun::create([
        'workflow' => AutomationRun::OVERDUE_INVOICES,
        'trigger' => 'manual',
        'status' => 'running',
        'started_at' => now(),
    ]));

    $this->actingAs($this->developer)->getJson('/api/v1/automation/runs')
        ->assertOk()
        ->assertJsonCount(0, 'data.history')
        ->assertJsonCount(0, 'data.active');
    $this->getJson("/api/v1/automation/runs/{$foreignRun->id}")->assertNotFound();

    $driver = User::create([
        'name' => 'Driver',
        'email' => 'automation-driver@example.test',
        'password' => 'password',
        'role' => 'driver',
    ]);
    $this->actingAs($driver)->getJson('/api/v1/automation/runs')->assertForbidden();
    $this->getJson('/api/v1/automation/bots/discord')->assertForbidden();
    $this->putJson('/api/v1/automation/bots/discord', ['enabled' => true])->assertForbidden();
});

it('lets a role manage tenant Discord settings through Development without granting access to credentials', function (): void {
    $this->actingAs($this->admin)->getJson('/api/v1/access/permissions')
        ->assertOk()->assertJsonFragment(['key' => 'automation.manage', 'name' => 'Developer Access', 'group' => 'Development']);
    $this->getJson('/api/v1/navigation')->assertOk()->assertJsonMissing(['key' => 'automation']);
    $this->getJson('/api/v1/automation/runs')->assertForbidden();
    $this->getJson('/api/v1/automation/bots/discord')->assertForbidden();
    $this->putJson('/api/v1/automation/bots/discord', ['enabled' => true])->assertForbidden();
    $this->putJson('/api/v1/assistant/settings', ['discord_finance_channel_id' => '222222222222222222'])
        ->assertForbidden();
    $role = $this->postJson('/api/v1/access/roles', [
        'name' => 'Automation Developer',
        'permissions' => ['automation.manage'],
    ])->assertCreated()->json('data.key');

    $developer = User::create([
        'name' => 'Automation Developer',
        'email' => 'automation-developer@example.test',
        'password' => 'password',
        'role' => $role,
    ]);

    $assistant = app(AssistantService::class);
    $assistant->update(['api_key' => 'test-assistant-key']);
    $other = $this->makeCompany('Other Discord Company');
    $this->asCompany($other, fn () => $assistant->update(['discord_finance_channel_id' => '999999999999999999']));

    $this->actingAs($developer)->getJson('/api/v1/navigation')->assertOk()
        ->assertJsonFragment(['key' => 'automation', 'group' => 'Development'])
        ->assertJsonMissing(['key' => 'assistant-settings']);
    $this->getJson('/api/v1/automation/runs')->assertOk();
    $this->getJson('/api/v1/automation/bots/discord')->assertOk()
        ->assertJsonPath('data.discord_finance_channel_id', null)
        ->assertJsonPath('data.chat_configured', true)
        ->assertJsonMissingPath('data.api_key')
        ->assertJsonMissingPath('data.key_hint');
    $this->getJson('/api/v1/assistant/settings')->assertForbidden();
    $this->postJson('/api/v1/assistant/token')->assertForbidden();

    $this->putJson('/api/v1/automation/bots/discord', [
        'enabled' => true,
        'discord_assistant_channel_id' => '111111111111111111',
        'discord_finance_channel_id' => '222222222222222222',
        'discord_finance_alerts_channel_id' => '333333333333333333',
        'discord_payment_alert_pesos' => 15000,
        'bot_role' => 'administrator',
        'api_key' => 'attempted-replacement-key',
        'clear_discord_token' => true,
    ])->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.discord_finance_channel_id', '222222222222222222')
        ->assertJsonPath('data.discord_payment_alert_pesos', 15000)
        ->assertJsonMissingPath('data.bot_role');

    expect($assistant->settings()->bot_role)->toBe('accountant')
        ->and($assistant->settings()->llm_api_key)->toBe('test-assistant-key')
        ->and($this->asCompany($other, fn () => $assistant->settings()->discord_finance_channel_id))
        ->toBe('999999999999999999');

    $this->actingAs($this->admin)->getJson('/api/v1/assistant/settings')->assertOk()
        ->assertJsonMissingPath('data.discord_finance_alerts_channel_id');

    $assignedRole = $developer->roleRecord;
    $this->putJson("/api/v1/access/roles/{$assignedRole->id}", ['status' => 'inactive'])->assertOk();
    $this->actingAs($developer->fresh())->getJson('/api/v1/automation/bots/discord')->assertForbidden();
    $this->getJson('/api/v1/navigation')->assertOk()->assertJsonMissing(['key' => 'automation']);
});

it('shows work while running and keeps a failed run with its end time', function (): void {
    $this->actingAs($this->developer);

    $this->mock(BillingService::class)->shouldReceive('reconcileOverdue')->once()
        ->andReturnUsing(function (): never {
            $this->getJson('/api/v1/automation/runs')
                ->assertOk()
                ->assertJsonPath('data.active.0.status', 'running');

            throw new RuntimeException('Simulated billing failure');
        });

    $this->artisan('cargo:invoices-overdue')
        ->expectsOutputToContain('Simulated billing failure')
        ->assertFailed();

    $this->getJson('/api/v1/automation/runs')
        ->assertOk()
        ->assertJsonCount(0, 'data.active')
        ->assertJsonPath('data.history.0.status', 'failed');
    expect(AutomationRun::query()->sole()->finished_at)->not->toBeNull();
});

it('lets an administrator explicitly enable and revoke Developer Access for their own profile', function (): void {
    $otherAdmin = User::create([
        'name' => 'Another Administrator',
        'email' => 'another-admin@example.test',
        'password' => 'password',
        'role' => 'administrator',
    ]);

    $this->actingAs($this->admin)->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.can_edit_developer_access', true)
        ->assertJsonPath('data.developer_access', false);
    $this->getJson('/api/v1/automation/runs')->assertForbidden();

    $this->putJson('/api/v1/me/developer-access', ['enabled' => true, 'user_id' => $otherAdmin->id])
        ->assertOk()->assertJsonPath('data.id', $this->admin->id)
        ->assertJsonPath('data.developer_access', true)
        ->assertJsonFragment(['permissions' => ['*', 'automation.manage']]);
    $this->actingAs($this->admin->fresh())->getJson('/api/v1/navigation')->assertOk()
        ->assertJsonFragment(['key' => 'automation', 'group' => 'Development']);
    $this->getJson('/api/v1/automation/runs')->assertOk();

    $this->actingAs($otherAdmin)->getJson('/api/v1/automation/runs')->assertForbidden();
    $this->actingAs($this->developer)->putJson('/api/v1/me/developer-access', ['enabled' => true])
        ->assertForbidden();

    $this->actingAs($this->admin->fresh())->putJson('/api/v1/me/developer-access', ['enabled' => false])
        ->assertOk()->assertJsonPath('data.developer_access', false);
    $this->actingAs($this->admin->fresh())->getJson('/api/v1/automation/runs')->assertForbidden();
});

it('does not allow a bearer token to change Developer Access', function (): void {
    $token = $this->admin->createToken('admin-device', ['*'])->plainTextToken;

    $this->withToken($token)->putJson('/api/v1/me/developer-access', ['enabled' => true])
        ->assertForbidden();
    expect($this->admin->fresh()->developer_access)->toBeFalse();
});
