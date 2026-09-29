<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;

/**
 * What a customer is shown about the money, and how they can check it.
 *
 * The office reads an invoice as a row it can cross-check against a ledger. A
 * customer reads one figure on a phone and has nothing to check it against —
 * which is how a wrong total went unquestioned for a month, and how a right one
 * gets queried. So the portal sends the **liquidation**: the haul, the net, the
 * VAT, anything withheld, every payment received, and what is left.
 *
 * Two other decisions are pinned here. The list is ordered by the day the
 * customer *asked* for the pickup rather than the day the office raised the
 * paperwork, because that is the date they know it by. And a delivered run is
 * finished business — the API still returns it, and the app shows it only under
 * History.
 */
beforeEach(function (): void {
    // Pricing is zone-only, and an unpriced run cannot go out or be billed.
    // One card at the old tariff's figures, so every run here is priced.
    zoneCard();

    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();
    $this->customer = Customer::where('name', 'Negros Fresh Mart')->firstOrFail();
    $this->customer->update(['withholds_tax' => false]);
    $this->buyer = User::where('email', 'orders@negrosfresh.ph')->firstOrFail();

    /**
     * A haul the customer asked for, taken out and handed over — which is what
     * raises the receivable.
     *
     * `requestedFor` is the pickup day they asked for, and it is deliberately
     * not today: the whole point of the ordering is that it differs from the
     * day the document was raised.
     */
    $this->haul = function (string $requestedFor, string $cargo = 'Chilled produce'): string {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            'origin' => 'Bacolod',
            'destination' => 'Iloilo',
            'cargo' => $cargo,
            'weight_kg' => 1800,
            'customer_id' => $this->customer->id,
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => $requestedFor,
            'status' => StatusValue::Assigned->value,
        ])->json('data.id');

        $this->passPreTripCheck($id);
        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertOk();
        $this->actingAs($this->marco)
            ->postJson('/api/v1/trips/current/deliver', ['receiver_name' => 'R. Uy'])
            ->assertOk();

        return $id;
    };

    $this->invoices = fn (): array => $this->actingAs($this->buyer)
        ->getJson('/api/v1/portal/invoices')
        ->assertOk()
        ->json('data');
});

describe('the liquidation', function (): void {
    it('shows the haul the invoice is for', function (): void {
        ($this->haul)(now()->subDays(2)->toIso8601String(), 'Chilled produce, 8 crates');

        $invoice = ($this->invoices)()[0];

        // Without the run on it, reconciling an invoice against a delivery is a
        // customer matching dates and figures by eye.
        expect($invoice['trip_reference'])->toStartWith('CR-')
            ->and($invoice['trip_origin'])->toBe('Bacolod')
            ->and($invoice['trip_destination'])->toBe('Iloilo')
            ->and($invoice['trip_cargo'])->toBe('Chilled produce, 8 crates')
            ->and($invoice['trip_weight_kg'])->toBe(1800)
            ->and($invoice['trip_status'])->toBe(StatusValue::Delivered->value);
    });

    it('adds up in front of the customer', function (): void {
        ($this->haul)(now()->subDay()->toIso8601String());

        $invoice = ($this->invoices)()[0];

        // net + VAT = total, total − withholding = payable, payable − received
        // = balance. Every step is on the payload so the screen can show the
        // arithmetic rather than the answer.
        expect($invoice['net_amount_cents'] + $invoice['vat_cents'])->toBe($invoice['amount_cents'])
            ->and($invoice['amount_cents'] - $invoice['withholding_cents'])->toBe($invoice['due_cents'])
            ->and($invoice['due_cents'] - $invoice['paid_cents'])->toBe($invoice['balance_cents'])
            // The rate the document was issued at, so the screen can print
            // "VAT (12%)" rather than making the customer take it on trust.
            ->and($invoice['vat_rate_bp'])->toBe(1200);
    });

    it('lists every payment against it, with its reference', function (): void {
        ($this->haul)(now()->subDay()->toIso8601String());

        $raised = Invoice::query()->firstOrFail();

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 200000,
            'paid_on' => now()->subDay()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'BDO-4471',
            'allocations' => [['invoice_id' => $raised->id, 'amount_cents' => 200000]],
        ])->assertCreated();

        $invoice = ($this->invoices)()[0];

        expect($invoice['payments'])->toHaveCount(1)
            ->and($invoice['payments'][0]['amount_cents'])->toBe(200000)
            ->and($invoice['payments'][0]['reference'])->toBe('BDO-4471')
            // `bank_transfer` as somebody would say it.
            ->and($invoice['payments'][0]['method_label'])->toBe('Bank transfer')
            // And the balance moves with it, measured rather than flagged.
            ->and($invoice['paid_cents'])->toBe(200000)
            ->and($invoice['balance_cents'])->toBe($invoice['due_cents'] - 200000)
            ->and($invoice['status'])->toBe(StatusValue::Partial->value);
    });

    it('says nothing has been received when nothing has', function (): void {
        ($this->haul)(now()->subDay()->toIso8601String());

        $invoice = ($this->invoices)()[0];

        expect($invoice['payments'])->toBe([])
            ->and($invoice['paid_cents'])->toBe(0)
            ->and($invoice['balance_cents'])->toBe($invoice['due_cents']);
    });

    it('shows the withholding only where the customer withholds', function (): void {
        $this->customer->update(['withholds_tax' => true, 'withholding_rate_bp' => 200]);

        ($this->haul)(now()->subDay()->toIso8601String());

        $invoice = ($this->invoices)()[0];

        // 2% of the gross, which is how the BIR computes it — so what the
        // customer actually sends is less than the invoice total, on purpose.
        expect($invoice['withholding_cents'])->toBeGreaterThan(0)
            ->and($invoice['withholding_rate_bp'])->toBe(200)
            ->and($invoice['due_cents'])->toBe($invoice['amount_cents'] - $invoice['withholding_cents']);
    });
});

describe('the order they are read in', function (): void {
    it('puts the most recently requested pickup first', function (): void {
        // Asked for in this order, and the documents are raised in the same
        // sitting — so issue dates cannot tell them apart and the requested day
        // is the only thing that can.
        ($this->haul)(now()->subDays(10)->toIso8601String(), 'Ten days ago');
        ($this->haul)(now()->subDays(2)->toIso8601String(), 'Two days ago');
        ($this->haul)(now()->subDays(6)->toIso8601String(), 'Six days ago');

        $cargo = array_column(($this->invoices)(), 'trip_cargo');

        expect($cargo)->toBe(['Two days ago', 'Six days ago', 'Ten days ago']);
    });

    it('carries the requested day on every row', function (): void {
        $requested = now()->subDays(3);

        ($this->haul)($requested->toIso8601String());

        $invoice = ($this->invoices)()[0];

        expect($invoice['requested_at'])->toStartWith($requested->format('Y-m-d'));
    });
});
