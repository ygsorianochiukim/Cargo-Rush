<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create(['role' => Role::Administrator]);
});

it('lists invoices and bills by number, newest first, whatever their dates', function (): void {
    $invoice = fn (string $number, string $issued, string $direction = 'receivable') => Invoice::create([
        'number' => $number, 'payee' => 'Acme', 'direction' => $direction,
        'issued_at' => $issued, 'due_at' => $issued, 'amount_cents' => 10_000, 'status' => 'pending',
    ]);

    $invoice('INV-2026-0002', '2026-01-05');
    $invoice('INV-2026-0003', '2026-01-01');
    $invoice('INV-2026-0001', '2026-03-01');
    $invoice('INV-2025-0009', '2026-04-01');
    $invoice('INV-2026-10000', '2026-01-02');

    $numbers = collect($this->actingAs($this->admin)->getJson('/api/v1/billing?per_page=100')->assertOk()->json('data'))
        ->pluck('number')
        ->all();

    expect($numbers)->toBe(['INV-2026-10000', 'INV-2026-0003', 'INV-2026-0002', 'INV-2026-0001', 'INV-2025-0009']);
});

it('lists journal entries by JV number, newest first', function (): void {
    $entry = fn (string $reference, string $date) => JournalEntry::create([
        'reference' => $reference, 'entry_date' => $date, 'memo' => 'x', 'category' => 'disbursement',
    ]);

    $entry('JV-0001', '2026-05-01');
    $entry('JV-0003', '2026-01-01');
    $entry('JV-0002', '2026-09-01');

    expect(JournalEntry::query()->inJournalOrder()->pluck('reference')->all())
        ->toBe(['JV-0003', 'JV-0002', 'JV-0001']);
});

it('lists delivery logs by trip number, newest first, whatever the delivery time', function (): void {
    zoneCard();

    $book = fn () => $this->actingAs($this->admin)->postJson('/api/v1/trips', [
        'origin' => 'Cagayan de Oro', 'destination' => 'Iligan', 'cargo' => 'Dry goods',
        'weight_kg' => 500, 'distance_total_m' => 34_000,
        'scheduled_at' => now()->addHours(3)->toIso8601String(),
    ])->assertCreated()->json('data.id');

    $first = $book();
    $second = $book();
    $third = $book();

    DeliveryLog::where('trip_id', $first)->update(['delivered_at' => now()->addDays(3)]);
    DeliveryLog::where('trip_id', $second)->update(['delivered_at' => now()->addDays(1)]);
    DeliveryLog::where('trip_id', $third)->update(['delivered_at' => now()->addDays(2)]);

    $trips = collect($this->actingAs($this->admin)->getJson('/api/v1/delivery-logs?per_page=100')->assertOk()->json('data'))
        ->pluck('trip_id')
        ->all();

    expect($trips)->toBe([$third, $second, $first])
        ->and(Trip::find($third)->reference_seq)->toBeGreaterThan(Trip::find($first)->reference_seq);
});
