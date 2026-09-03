<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\InvoiceDirection;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;

/**
 * The invoice number is the system's to assign, not the client's.
 *
 * Two people filling the billing form at once used to race for the same
 * number, and the unique index rejected the loser as a validation error on a
 * field neither of them should have been typing. It also has to be right: a
 * number that repeats or skips is the kind of thing an auditor asks about.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->customer = Customer::first() ?? Customer::create([
        'name' => 'Southline Trading',
        'contact' => 'Ana Cruz',
        'phone' => '09170000000',
    ]);

    $this->receivable = [
        'customer_id' => $this->customer->id,
        'direction' => 'receivable',
        'issued_at' => now()->toDateString(),
        'due_at' => now()->addDays(30)->toDateString(),
        'amount_cents' => 12_500_00,
    ];

    $this->payable = [
        'payee' => 'Petron Fleet Card',
        'direction' => 'payable',
        'issued_at' => now()->toDateString(),
        'due_at' => now()->addDays(15)->toDateString(),
        'amount_cents' => 96_200_00,
    ];
});

it('assigns the number itself', function (): void {
    $year = now()->year;

    $this->actingAs($this->admin)
        ->postJson('/api/v1/billing', $this->receivable)
        ->assertCreated()
        ->assertJsonPath('data.number', "INV-{$year}-0001");
});

it('keeps receivables and payables on their own series', function (): void {
    $year = now()->year;

    // The two are reconciled against different people, so sharing a run of
    // numbers between them would make neither series readable.
    $this->actingAs($this->admin)->postJson('/api/v1/billing', $this->receivable)
        ->assertJsonPath('data.number', "INV-{$year}-0001");

    $this->actingAs($this->admin)->postJson('/api/v1/billing', $this->payable)
        ->assertJsonPath('data.number', "BILL-{$year}-0001");

    $this->actingAs($this->admin)->postJson('/api/v1/billing', $this->receivable)
        ->assertJsonPath('data.number', "INV-{$year}-0002");
});

it('refuses a number chosen by the client', function (): void {
    // Not in the rules, so it is stripped rather than honoured.
    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/billing', [...$this->receivable, 'number' => 'INV-1999-9999'])
        ->assertCreated();

    expect($response->json('data.number'))->not->toBe('INV-1999-9999')
        ->and($response->json('data.number'))->toBe('INV-'.now()->year.'-0001');
});

it('never reissues a number a deleted invoice still holds', function (): void {
    // `number` is unique across soft-deleted rows too, so a series that
    // ignored them would collide on insert the moment anything was deleted.
    $first = $this->actingAs($this->admin)->postJson('/api/v1/billing', $this->receivable)
        ->json('data.id');

    $this->actingAs($this->admin)->deleteJson("/api/v1/billing/{$first}")->assertSuccessful();

    $this->actingAs($this->admin)->postJson('/api/v1/billing', $this->receivable)
        ->assertCreated()
        ->assertJsonPath('data.number', 'INV-'.now()->year.'-0002');
});

it('restarts the count each year', function (): void {
    // The printed series is per year, so the number carries its year.
    Invoice::create([...$this->receivable, 'number' => 'INV-2025-0417', 'currency' => 'PHP']);

    $this->actingAs($this->admin)->postJson('/api/v1/billing', $this->receivable)
        ->assertJsonPath('data.number', 'INV-'.now()->year.'-0001');
});

it('counts past the padding without repeating itself', function (): void {
    // 9999 → 10000 is where a plain string sort breaks: it puts "-10000"
    // before "-9999" and the series starts handing out numbers it has used.
    $year = now()->year;

    Invoice::create([...$this->receivable, 'number' => "INV-{$year}-9999", 'currency' => 'PHP']);

    $this->actingAs($this->admin)->postJson('/api/v1/billing', $this->receivable)
        ->assertJsonPath('data.number', "INV-{$year}-10000");

    $this->actingAs($this->admin)->postJson('/api/v1/billing', $this->receivable)
        ->assertJsonPath('data.number', "INV-{$year}-10001");
});

it('numbers an invoice made outside the API too', function (): void {
    // Seeders, factories and console commands go through the model, not the
    // controller, so the number cannot live in a request class.
    $invoice = Invoice::create([...$this->receivable, 'currency' => 'PHP']);

    expect($invoice->number)->toBe('INV-'.now()->year.'-0001');
});

it('leaves a number that was given on purpose', function (): void {
    // The demo seeder transcribes the real book, and those numbers are the
    // business's own. An explicit value is honoured; only a missing one is
    // generated.
    $invoice = Invoice::create([
        ...$this->receivable,
        'number' => 'INV-2026-0441',
        'currency' => 'PHP',
    ]);

    expect($invoice->number)->toBe('INV-2026-0441');
});

it('numbers a payable by its own prefix when built directly', function (): void {
    $invoice = Invoice::create([...$this->payable, 'currency' => 'PHP']);

    expect($invoice->number)->toBe('BILL-'.now()->year.'-0001')
        ->and($invoice->direction)->toBe(InvoiceDirection::Payable);
});
