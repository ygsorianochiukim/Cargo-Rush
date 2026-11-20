<?php

declare(strict_types=1);

use App\Domain\Assistant\Services\AssistantService;
use App\Domain\Finance\Models\DiscordExpenseReceipt;
use App\Domain\Finance\Models\Expense;
use App\Domain\Identity\Models\User;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(CompanyProvisioner::class)->provision($this->company);
    $this->financeChannel = '111111111111111111';
    $this->discordUser = '222222222222222222';
    $assistant = app(AssistantService::class);
    $assistant->update(['enabled' => true, 'discord_finance_channel_id' => $this->financeChannel]);
    $assistant->addDiscordUser($this->discordUser, 'Accountant', true);
    $this->withToken($assistant->issueToken());
    $this->identity = ['channel_id' => $this->financeChannel, 'discord_user_id' => $this->discordUser];
    $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jR1sAAAAASUVORK5CYII=');
    $this->upload = fn (string $attachment = '333333333333333333') => $this->post('/api/v1/assistant/receipts', [
        ...$this->identity,
        'attachment_id' => $attachment,
        'image' => UploadedFile::fake()->createWithContent('receipt.png', $this->png),
    ], ['Accept' => 'application/json']);
    $this->decide = fn (string $id, string $action, int $revision = 0, array $extra = []) => $this->postJson(
        "/api/v1/assistant/receipts/{$id}",
        [...$this->identity, 'action' => $action, 'revision' => $revision, ...$extra],
    );
    $this->fields = [
        'date' => '2026-11-20', 'amount' => '105.25', 'currency' => 'PHP',
        'category' => 'office', 'vat' => 'no', 'payee' => 'Paper Supply', 'reference' => 'R-112',
    ];
});

it('validates previews and confirms only the current revision exactly once', function (): void {
    $id = ($this->upload)()->assertOk()
        ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.may_confirm', true)
        ->assertJsonPath('data.categories.office', 'Office & Admin')->json('data.id');
    ($this->upload)()->assertOk()->assertJsonPath('data.id', $id);
    ($this->decide)($id, 'confirm')->assertUnprocessable();
    expect(Expense::query()->count())->toBe(0);

    ($this->decide)($id, 'update', 0, ['fields' => $this->fields])->assertOk()
        ->assertJsonPath('data.revision', 1)->assertJsonCount(0, 'data.issues');
    ($this->decide)($id, 'confirm', 0)->assertConflict();
    ($this->decide)($id, 'confirm', 1)->assertOk()->assertJsonPath('data.status', 'confirmed');
    ($this->decide)($id, 'confirm', 1)->assertOk()->assertJsonPath('data.status', 'confirmed');

    $expense = Expense::query()->sole();
    expect($expense->amount_cents)->toBe(10525)
        ->and($expense->reference)->toBe('R-112')
        ->and($expense->supplier_id)->toBeNull()
        ->and($expense->payee)->toBe('Paper Supply')
        ->and(DiscordExpenseReceipt::query()->sole()->expense_id)->toBe($expense->id)
        ->and(DB::table('discord_expense_receipts')->value('original'))->not->toBe(base64_encode($this->png));

    $duplicate = ($this->upload)('555555555555555555')->assertOk()->json('data.id');
    ($this->decide)($duplicate, 'update', 0, ['fields' => $this->fields])->assertOk()
        ->assertJsonPath('data.issues.0', 'This exact receipt image was already recorded.');
    ($this->decide)($duplicate, 'confirm', 1)->assertUnprocessable();
    expect(Expense::query()->count())->toBe(1);
});

it('links a unique active supplier by payee and leaves ambiguous or foreign matches unlinked', function (): void {
    $supplier = Supplier::create(['name' => 'Paper Supply', 'status' => 'active']);
    $other = $this->makeCompany('Other Receipt Company');
    $this->asCompany($other, fn () => Supplier::create(['name' => 'Paper Supply', 'status' => 'active']));
    Supplier::create(['name' => 'PAPER SUPPLY', 'status' => 'inactive']);

    $id = ($this->upload)()->assertOk()->json('data.id');
    ($this->decide)($id, 'update', 0, ['fields' => [...$this->fields, 'payee' => 'PAPER   SUPPLY.']])
        ->assertOk()->assertJsonPath('data.fields.supplier_id', $supplier->id);
    ($this->decide)($id, 'update', 1, ['fields' => ['supplier_id' => null]])
        ->assertOk()->assertJsonPath('data.fields.supplier_id', null);

    Supplier::create(['name' => 'Paper   Supply', 'status' => 'active']);
    $id = ($this->upload)('666666666666666666')->assertOk()->json('data.id');
    ($this->decide)($id, 'update', 0, ['fields' => $this->fields])
        ->assertOk()->assertJsonPath('data.fields.supplier_id', null)->assertJsonCount(0, 'data.issues');
});

it('archives cancelled drafts and every revision without creating an expense', function (): void {
    $id = ($this->upload)()->assertOk()->json('data.id');
    ($this->decide)($id, 'update', 0, ['fields' => $this->fields])->assertOk();
    ($this->decide)($id, 'update', 1, ['fields' => ['amount' => '110.00']])->assertOk();
    ($this->decide)($id, 'cancel', 2)->assertOk()->assertJsonPath('data.status', 'archived');
    ($this->decide)($id, 'confirm', 2)->assertConflict();
    expect(Expense::withTrashed()->count())->toBe(0);

    $viewer = User::create([
        'name' => 'Finance Viewer', 'email' => 'receipt-viewer@test.test',
        'password' => 'password', 'role' => 'accountant',
    ]);
    $this->actingAs($viewer)->getJson('/api/v1/expenses/receipt-archive')->assertOk()
        ->assertJsonPath('data.0.status', 'archived')
        ->assertJsonPath('data.0.fields.amount', '110.00')
        ->assertJsonPath('data.0.history.1.fields.amount', '105.25')
        ->assertJsonMissingPath('data.0.original');
    expect($this->get("/api/v1/expenses/receipt-archive/{$id}/original")->assertOk()->streamedContent())->toBe($this->png);
});

it('matches receipt abbreviations and OCR errors without choosing between close suppliers', function (): void {
    $supplier = Supplier::create(['name' => 'National Testing Board Inc.', 'status' => 'active']);
    $id = ($this->upload)()->assertOk()->json('data.id');
    ($this->decide)($id, 'update', 0, ['fields' => [...$this->fields, 'payee' => 'NATL TESTING BRD']])
        ->assertOk()->assertJsonPath('data.fields.supplier_id', $supplier->id)
        ->assertJsonPath('data.supplier_name', $supplier->name);
    ($this->decide)($id, 'update', 1, ['fields' => ['payee' => 'NATIONAL TESTIN BOARD']])
        ->assertOk()->assertJsonPath('data.fields.supplier_id', $supplier->id);
    ($this->decide)($id, 'update', 2, ['fields' => ['payee' => 'NTB']])
        ->assertOk()->assertJsonPath('data.fields.supplier_id', $supplier->id);

    Supplier::create(['name' => 'National Testin Boards', 'status' => 'active']);
    $id = ($this->upload)('777777777777777777')->assertOk()->json('data.id');
    ($this->decide)($id, 'update', 0, ['fields' => [...$this->fields, 'payee' => 'NATIONAL TESTIN BOARD']])
        ->assertOk()->assertJsonPath('data.fields.supplier_id', null);
    ($this->decide)($id, 'update', 1, ['fields' => ['payee' => 'NTB']])
        ->assertOk()->assertJsonPath('data.fields.supplier_id', null);
});

it('lets a signed browser editor update the draft without posting an expense', function (): void {
    $id = ($this->upload)()->assertOk()->json('data.id');
    $this->postJson("/api/v1/assistant/receipts/{$id}/edit-link", [...$this->identity, 'discord_user_id' => '444444444444444444'])
        ->assertNotFound();
    $url = $this->postJson("/api/v1/assistant/receipts/{$id}/edit-link", $this->identity)
        ->assertOk()->json('data.url');
    parse_str(parse_url($url, PHP_URL_FRAGMENT), $link);
    $path = "/api/v1/expenses/receipt-edit/{$id}";
    $this->postJson($path.'/open', $link)->assertForbidden();

    $editor = User::create([
        'name' => 'Finance editor', 'email' => 'receipt-editor@test.test',
        'password' => 'password', 'role' => 'accountant',
    ]);
    $this->flushHeaders()->actingAs($editor)->postJson($path.'/open', $link)->assertOk()
        ->assertJsonPath('data.preview.revision', 0);
    $this->putJson($path, [...$link, 'revision' => 0, 'fields' => $this->fields])
        ->assertOk()->assertJsonPath('data.revision', 1)->assertJsonCount(0, 'data.issues');
    $this->putJson($path, [...$link, 'revision' => 0, 'fields' => ['amount' => '111.11']])->assertConflict();
    expect(Expense::query()->count())->toBe(0);

    $this->travel(31)->minutes();
    $this->postJson($path.'/open', $link)->assertForbidden();
});

it('requires the original Discord submitter and an explicit confirmation grant', function (): void {
    $assistant = app(AssistantService::class);
    $second = '444444444444444444';
    $assistant->addDiscordUser($second, 'Second accountant', true);
    $assistant->addDiscordUser($this->discordUser, 'Accountant', false);
    $id = ($this->upload)()->assertOk()->assertJsonPath('data.may_confirm', false)->json('data.id');
    ($this->decide)($id, 'update', 0, ['fields' => $this->fields])->assertOk();
    ($this->decide)($id, 'confirm', 1)->assertForbidden();
    ($this->decide)($id, 'cancel', 1, ['discord_user_id' => $second])->assertNotFound();
    ($this->decide)($id, 'cancel', 1, ['channel_id' => $second])->assertForbidden();
    ($this->decide)($id, 'cancel', 1)->assertOk();
    $this->postJson('/api/v1/expenses', ['amount_cents' => 10000])->assertForbidden();
});
