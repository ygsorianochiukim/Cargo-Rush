<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\WalletEntry;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;

/**
 * What customers still owed the fleet at the close of a quarter.
 *
 * Wound back to the date, like Payables: a bill paid in October was still owed
 * on 30 September, and one raised in October was not owed yet. The Summary tile
 * and the list it opens are pinned to the same figure.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    // Into October, so a payment made after the quarter closed can be filed.
    $this->travelTo(now()->setDate(2026, 10, 10));

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]);

    $this->customer = Customer::create(['name' => 'Iligan Cement Corp', 'contact' => '0917 555 0101']);

    $this->invoice = fn (int $cents, string $issued = '2026-08-01', string $due = '2026-08-31') => $this->actingAs($this->admin)
        ->postJson('/api/v1/billing', [
            'customer_id' => $this->customer->id,
            'issued_at' => $issued,
            'due_at' => $due,
            'amount_cents' => $cents,
            'direction' => 'receivable',
        ])->assertCreated()->json('data');

    /** What the customer actually owes on one: VAT on, withholding off. */
    $this->due = fn (array $invoice): int => Invoice::findOrFail($invoice['id'])->dueCents();

    $this->collect = fn (string $invoiceId, int $cents, string $paidOn) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'direction' => 'receivable',
            'amount_cents' => $cents,
            'paid_on' => $paidOn,
            'method' => 'bank_transfer',
            'allocations' => [['invoice_id' => $invoiceId, 'amount_cents' => $cents]],
        ])->assertCreated();

    $this->lines = fn (string $asOf = '2026-09-30') => $this->actingAs($this->admin)
        ->getJson("/api/v1/finance/receivable-lines?as_of={$asOf}")
        ->assertOk()
        ->json('data');

    $this->tile = fn () => $this->actingAs($this->admin)
        ->getJson('/api/v1/finance/summary?year=2026&quarter=q3')
        ->assertOk()
        ->json('data.totals.receivables_cents');
});

it('counts what was still unpaid at the close, and matches the tile', function (): void {
    $partPaid = ($this->invoice)(1_000_000);
    ($this->collect)($partPaid['id'], 400_000, '2026-09-10');

    // Settled after the quarter closed, so it was still owed on 30 September.
    $paidLater = ($this->invoice)(250_000);
    ($this->collect)($paidLater['id'], ($this->due)($paidLater), '2026-10-05');

    // Raised after the close: not owed yet.
    ($this->invoice)(900_000, '2026-10-02', '2026-10-30');

    $report = ($this->lines)();

    expect($report['total_cents'])->toBe(($this->due)($partPaid) - 400_000 + ($this->due)($paidLater))
        ->and(($this->tile)())->toBe($report['total_cents'])
        ->and(array_column($report['lines'], 'counterparty'))->each->toBe('Iligan Cement Corp')
        ->and($report['lines'][0]['overdue'])->toBeTrue();
});

it('leaves out what was paid in full and what was cancelled', function (): void {
    $paid = ($this->invoice)(500_000);
    ($this->collect)($paid['id'], ($this->due)($paid), '2026-08-15');

    $cancelled = ($this->invoice)(700_000);
    Invoice::findOrFail($cancelled['id'])->update(['status' => StatusValue::Cancelled]);

    expect(($this->lines)()['lines'])->toBe([])
        ->and(($this->tile)())->toBe(0);
});

it('counts a partner whose wallet was in the red, and not one in credit', function (): void {
    $owes = Trucker::factory()->approved()->create(['name' => 'Boyet Aquino']);
    $owed = Trucker::factory()->approved()->create(['name' => 'Jun Dela Cruz']);

    WalletEntry::create([
        'trucker_id' => $owes->getKey(), 'kind' => WalletEntryKind::Commission->value,
        'status' => StatusValue::Paid->value, 'amount_cents' => -120_000, 'occurred_on' => '2026-09-01',
    ]);
    WalletEntry::create([
        'trucker_id' => $owed->getKey(), 'kind' => WalletEntryKind::Earning->value,
        'status' => StatusValue::Paid->value, 'amount_cents' => 300_000, 'occurred_on' => '2026-09-01',
    ]);

    $lines = ($this->lines)()['lines'];

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['counterparty'])->toBe('Boyet Aquino')
        ->and($lines[0]['amount_cents'])->toBe(120_000);
});

it('changes no other figure, because the income is already counted', function (): void {
    $invoice = ($this->invoice)(1_000_000);

    $totals = $this->actingAs($this->admin)
        ->getJson('/api/v1/finance/summary?year=2026&quarter=q3')
        ->json('data.totals');

    expect($totals['receivables_cents'])->toBe(($this->due)($invoice))
        ->and($totals['actual_income_cents'])->toBe($totals['net_income_cents'] - $totals['payables_cents']);
});
