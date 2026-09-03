<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use Database\Seeders\NavigationSeeder;

/**
 * A fresh install has navigation and one account, and nothing else.
 *
 * This is the state a client actually starts in, so it gets the same coverage
 * as a populated one. The failure it guards against is the one that makes an
 * empty system look broken: a list that errors instead of returning nothing,
 * or a create that 500s because it relied on a value the demo data supplied.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);

    $this->user = User::factory()->create(['role' => 'administrator']);
});

it('keeps the navigation, because a shell with no sidebar is not an empty system', function (): void {
    $this->actingAs($this->user)->getJson('/api/v1/navigation')
        ->assertOk()
        ->assertJsonPath('meta.client', 'web');

    expect(collect($this->actingAs($this->user)->getJson('/api/v1/navigation')->json('data')))
        ->not->toBeEmpty();
});

it('returns an empty list rather than an error for every module', function (string $path): void {
    $this->actingAs($this->user)->getJson("/api/v1/{$path}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
})->with([
    'trips', 'vehicles', 'drivers', 'customers',
    'fuel', 'billing', 'incidents', 'finance/trucks', 'ledger',
    'dispatch', 'delivery-logs', 'notifications', 'gps',
]);

it('answers the dashboard on a system with nothing in it', function (string $path): void {
    $this->actingAs($this->user)->getJson("/api/v1/{$path}")->assertOk();
})->with([
    'dashboard/kpis',
    'dashboard/fleet',
    'dashboard/deliveries',
    'dashboard/activity',
    'finance/profitability',
    'finance/summary',
    'billing/totals',
    'fuel/budget',
    'delivery-logs/report',
]);

it('reports no margin rather than a zero one when nothing has been earned', function (): void {
    $this->actingAs($this->user)->getJson('/api/v1/finance/profitability')
        ->assertOk()
        ->assertJsonPath('data.totals.margin', null)
        ->assertJsonPath('data.best_performer', null);
});

describe('entering the first records', function (): void {
    /**
     * The status column has a database default, and `persistable()` omits what
     * the caller did not send — so a create with no status used to hand the
     * Resource a model whose enum was still null. This is that.
     */
    it('creates a driver without being told a status', function (): void {
        $this->actingAs($this->user)->postJson('/api/v1/drivers', [
            'name' => 'Real Driver',
            'licence_no' => 'X01-11-000001',
            'licence_expiry' => '2028-01-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'available');
    });

    it('creates a vehicle without being told a status', function (): void {
        $this->actingAs($this->user)->postJson('/api/v1/vehicles', [
            'plate' => 'REAL 0001',
            'model' => 'Isuzu Forward',
            'registration_no' => 'LTO-2026-00001',
            'capacity_kg' => 8000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'available');
    });

    it('creates a customer without being told a status', function (): void {
        $this->actingAs($this->user)->postJson('/api/v1/customers', [
            'name' => 'Real Customer',
            'contact' => 'ops@realcustomer.ph',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active');
    });

    it('starts the trip reference series from the beginning', function (): void {
        $this->actingAs($this->user)->postJson('/api/v1/trips', [
            'origin' => 'Pagadian',
            'destination' => 'Ozamis',
            'cargo' => 'Assorted retail',
            'weight_kg' => 2400,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.reference', 'CR-24801');
    });

    it('adds a ledger unit with no plate yet', function (): void {
        // Units 7 and 8 in the workbook have no plate. A business entering its
        // own fleet hits the same case on day one.
        $this->actingAs($this->user)->postJson('/api/v1/finance/trucks', [
            'label' => 'Unit 1',
        ])
            ->assertCreated()
            ->assertJsonPath('data.plate', null)
            ->assertJsonPath('data.position', 1);
    });

    it('refuses to delete a unit that still has money against it', function (): void {
        $truck = $this->actingAs($this->user)
            ->postJson('/api/v1/finance/trucks', ['label' => 'Unit 1'])
            ->json('data.id');

        $this->actingAs($this->user)->postJson('/api/v1/ledger', [
            'truck_id' => $truck,
            'date' => '2026-09-01',
            'trip_income_cents' => 3_072_100,
            'fuel_cents' => 500_000,
        ])->assertCreated();

        // Deleting it would take the money with it, and a period that used to
        // balance would quietly stop.
        $this->actingAs($this->user)
            ->deleteJson("/api/v1/finance/trucks/{$truck}")
            ->assertStatus(422);
    });
});
