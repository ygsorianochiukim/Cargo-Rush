<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Support\Money;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;

/**
 * The invoice as a document — the thing that leaves the building.
 *
 * A screen can leave a lot implicit. A document cannot: it is what a client's
 * accounts department files, what a collector carries, and what somebody reads
 * back in two years to settle an argument. So these tests are about the things
 * a printed invoice has to say and a list row does not — who is billing, both
 * TINs, what the haul was, what has been paid against it, and the amount in
 * words.
 *
 * Plus the export beside it, which is the same list somebody is looking at,
 * unpaginated, with all five tax figures on it. An export missing the
 * withholding cannot be reconciled against a bank statement, which is the only
 * reason anybody exports this.
 */
beforeEach(function (): void {
    // Pricing is zone-only, and an unpriced run cannot go out or be billed.
    // One card at the old tariff's figures, so every run here is priced.
    zoneCard();

    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    // The haulier, as it would be set up: a registered name, a TIN, an address
    // and somebody to ring.
    $this->company->update([
        'tin' => '008-123-456-000',
        'address' => 'Iponan, Cagayan de Oro City',
        'contact_name' => 'Elena Bautista',
        'contact_phone' => '0917 555 0100',
        'contact_email' => 'accounts@cargorush.ph',
    ]);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();

    $this->customer = Customer::where('name', 'Negros Fresh Mart')->firstOrFail();
    $this->customer->update([
        'tin' => '004-987-654-000',
        'address' => 'Bacolod City, Negros Occidental',
        'contact' => '0918 222 3344',
    ]);

    /** Book a run, take it out, hand it over — which raises the receivable. */
    $this->haul = function (array $overrides = []): string {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            'origin' => 'Bacolod',
            'destination' => 'Iloilo',
            'cargo' => 'Chilled produce, 8 crates',
            'weight_kg' => 1800,
            'customer_id' => $this->customer->id,
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->toIso8601String(),
            'status' => StatusValue::Assigned->value,
            ...$overrides,
        ])->json('data.id');

        // A unit does not roll without a passing pre-trip check.
        $this->passPreTripCheck($id);
        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertOk();
        $this->actingAs($this->marco)
            ->postJson('/api/v1/trips/current/deliver', ['receiver_name' => 'R. Uy'])
            ->assertOk();

        return $id;
    };

    $this->document = function (): array {
        $invoice = Invoice::query()->firstOrFail();

        return $this->actingAs($this->admin)
            ->getJson("/api/v1/billing/{$invoice->id}/document")
            ->assertOk()
            ->json('data');
    };
});

describe('the delivery invoice', function (): void {
    it('says who is billing, with their TIN and their mark', function (): void {
        ($this->haul)();

        $issuer = ($this->document)()['issuer'];

        // A document with no issuer on it is not an invoice, it is a figure.
        expect($issuer['name'])->toBe($this->company->name)
            ->and($issuer['tin'])->toBe('008-123-456-000')
            ->and($issuer['address'])->toBe('Iponan, Cagayan de Oro City')
            ->and($issuer['contact_phone'])->toBe('0917 555 0100')
            ->and($issuer['contact_email'])->toBe('accounts@cargorush.ph')
            // Null until somebody uploads one, which the clients render as the
            // company's initials rather than a gap.
            ->and($issuer)->toHaveKey('logo_url');
    });

    it('says who is being billed, with their TIN and VAT treatment', function (): void {
        ($this->haul)();

        $billTo = ($this->document)()['bill_to'];

        // A Philippine invoice is expected to carry both parties' TINs.
        expect($billTo['name'])->toBe('Negros Fresh Mart')
            ->and($billTo['tin'])->toBe('004-987-654-000')
            ->and($billTo['address'])->toBe('Bacolod City, Negros Occidental')
            ->and($billTo['contact'])->toBe('0918 222 3344')
            ->and($billTo['vat_treatment'])->not->toBeNull();
    });

    it('calls itself a delivery invoice, and says what was delivered', function (): void {
        ($this->haul)();

        $document = ($this->document)();

        // The haul is what makes it a *delivery* invoice rather than a demand
        // for money: the reference, the route, the load and who drove it.
        expect($document['document']['title'])->toBe('Delivery Invoice')
            ->and($document['lines'])->toHaveCount(1);

        $line = $document['lines'][0];

        expect($line['origin'])->toBe('Bacolod')
            ->and($line['destination'])->toBe('Iloilo')
            ->and($line['cargo'])->toBe('Chilled produce, 8 crates')
            ->and($line['weight_kg'])->toBe(1800)
            ->and($line['plate'])->toBe('NCR 4412')
            ->and($line['driver'])->toBe('Marco Reyes')
            ->and($line['reference'])->toStartWith('CR-')
            // Delivered today, off the delivery log rather than the schedule.
            ->and($line['delivered_at'])->toBe(now()->toDateString());
    });

    it('carries the five figures and the rates that made them', function (): void {
        ($this->haul)();

        $invoice = Invoice::query()->firstOrFail();
        $totals = ($this->document)()['totals'];

        // net + vat = amount, and amount - withholding = due.
        expect($totals['net_amount_cents'])->toBe($invoice->net_amount_cents)
            ->and($totals['vat_cents'])->toBe($invoice->vat_cents)
            ->and($totals['amount_cents'])->toBe($invoice->net_amount_cents + $invoice->vat_cents)
            ->and($totals['due_cents'])->toBe($invoice->amount_cents - $invoice->withholding_cents)
            // Frozen on the day, so the document reprints years later exactly
            // as it was issued whatever the rates are by then.
            ->and($totals['vat_rate_bp'])->toBe($invoice->vat_rate_bp)
            ->and($totals['vat_label'])->not->toBeNull();
    });

    it('writes the amount out in words', function (): void {
        ($this->haul)();

        $invoice = Invoice::query()->firstOrFail();
        $totals = ($this->document)()['totals'];

        // A figure in words cannot be turned into a bigger one with a pen,
        // which is why every printed invoice has carried one for a century.
        // It is the *due* figure — what the payer is actually asked for.
        expect($totals['amount_in_words'])
            ->toBe(Money::inWords($invoice->dueCents(), $invoice->currency))
            ->and($totals['amount_in_words'])->toContain('pesos');
    });

    it('works out the credit terms rather than storing them', function (): void {
        ($this->haul)();

        $document = ($this->document)()['document'];
        $invoice = Invoice::query()->firstOrFail();

        expect($document['issued_at'])->toBe($invoice->issued_at->toDateString())
            ->and($document['due_at'])->toBe($invoice->due_at->toDateString())
            ->and($document['terms_days'])->toBe(
                (int) $invoice->issued_at->diffInDays($invoice->due_at),
            );
    });

    it('shows every payment against it, and what is left', function (): void {
        ($this->haul)();

        $invoice = Invoice::query()->firstOrFail();

        // Two part payments, which is the case a reprinted invoice has to get
        // right: one that still asked for the full amount is the document that
        // starts the argument.
        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 100000,
            'paid_on' => now()->subDay()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'BDO-77120',
            'allocations' => [['invoice_id' => $invoice->id, 'amount_cents' => 100000]],
        ])->assertCreated();

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 50000,
            'paid_on' => now()->toDateString(),
            'method' => 'cash',
            'reference' => 'CR-2201',
            'allocations' => [['invoice_id' => $invoice->id, 'amount_cents' => 50000]],
        ])->assertCreated();

        $document = ($this->document)();

        expect($document['payments'])->toHaveCount(2)
            // Oldest first, which is the order a statement of account reads.
            ->and($document['payments'][0]['reference'])->toBe('BDO-77120')
            ->and($document['payments'][0]['amount_cents'])->toBe(100000)
            // `bank_transfer` as somebody would say it.
            ->and($document['payments'][0]['method_label'])->toBe('Bank transfer')
            ->and($document['payments'][1]['reference'])->toBe('CR-2201')
            ->and($document['totals']['paid_cents'])->toBe(150000)
            ->and($document['totals']['balance_cents'])
            ->toBe($invoice->refresh()->dueCents() - 150000);
    });

    it('calls a manual invoice with no haul behind it a sales invoice', function (): void {
        $invoice = $this->actingAs($this->admin)->postJson('/api/v1/billing', [
            'direction' => 'receivable',
            'customer_id' => $this->customer->id,
            'amount_cents' => 500000,
            'issued_at' => now()->toDateString(),
            'due_at' => now()->addDays(30)->toDateString(),
        ])->assertCreated()->json('data');

        $document = $this->actingAs($this->admin)
            ->getJson("/api/v1/billing/{$invoice['id']}/document")
            ->assertOk()
            ->json('data');

        // No delivery on it, so it does not claim to be one — and it still has
        // a description, because a printed invoice with an empty table is a
        // figure nobody can approve.
        expect($document['document']['title'])->toBe('Sales Invoice')
            ->and($document['lines'])->toHaveCount(1)
            ->and($document['lines'][0]['description'])->toBe('Freight and hauling services')
            ->and($document['lines'][0]['reference'])->toBeNull();
    });

    it('is refused to an account that cannot see billing', function (): void {
        ($this->haul)();

        $invoice = Invoice::query()->firstOrFail();

        // A driver holds no `billing.view`. Printing an invoice is a read, and
        // it is gated like every other read of one.
        $this->actingAs($this->marco)
            ->getJson("/api/v1/billing/{$invoice->id}/document")
            ->assertForbidden();
    });
});

describe('exporting the list', function (): void {
    it('gives back a spreadsheet of the invoices on screen', function (): void {
        ($this->haul)();

        $response = $this->actingAs($this->admin)->get('/api/v1/billing/export')->assertOk();

        expect($response->headers->get('content-type'))->toContain('text/csv');

        $csv = $response->streamedContent();
        $invoice = Invoice::query()->firstOrFail();

        // All five tax figures, not just the gross: a file that cannot be
        // reconciled against a bank statement is not worth exporting.
        expect($csv)->toContain('Number,Direction,Status,Customer,Trip')
            ->and($csv)->toContain('Withholding')
            ->and($csv)->toContain($invoice->number)
            ->and($csv)->toContain('Negros Fresh Mart')
            // Pesos with two decimals — the one place in the system where a
            // formatted amount is the right answer, because a person reads it.
            ->and($csv)->toContain(number_format($invoice->amount_cents / 100, 2, '.', ''));
    });

    it('honours the filters the list was looking at', function (): void {
        ($this->haul)();

        // Nothing is a payable, so a payables export is a header and no rows —
        // the export somebody wants is the one they are looking at.
        $csv = $this->actingAs($this->admin)
            ->get('/api/v1/billing/export?direction=payable')
            ->assertOk()
            ->streamedContent();

        expect($csv)->toContain('Number,Direction')
            ->and($csv)->not->toContain('Negros Fresh Mart');
    });
});
