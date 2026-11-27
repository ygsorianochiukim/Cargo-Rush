<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Support\Money;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    zoneCard();

    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

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
    it('marks selected trip invoices billed and settles them in full only after billing', function (?int $dateOffset): void {
        $first = ($this->haul)();
        $second = ($this->haul)();
        $invoices = Invoice::query()->whereIn('trip_id', [$first, $second])->orderBy('id')->get();
        $ids = $invoices->modelKeys();
        $endpoint = '/api/v1/billing/trip-status';
        $payment = [
            'invoice_ids' => $ids,
            'action' => 'paid',
            'method' => 'cash',
            'reference' => 'RECEIPT-42',
        ];
        $expectedDate = now('Asia/Manila')->addDays($dateOffset ?? 0)->toDateString();
        if ($dateOffset !== null) {
            $payment['paid_on'] = $expectedDate;
        }

        $this->actingAs($this->marco)->postJson($endpoint, $payment)->assertForbidden();
        $this->actingAs($this->admin)->postJson($endpoint, $payment)->assertUnprocessable();
        $this->postJson($endpoint, [
            'invoice_ids' => [$ids[0]],
            'action' => 'billed',
        ])->assertNoContent();

        $this->getJson("/api/v1/billing/{$ids[0]}/document")
            ->assertOk()
            ->assertJsonPath('data.billing_status', 'billed');
        $this->getJson("/api/v1/billing/{$ids[1]}")
            ->assertOk()
            ->assertJsonPath('data.billing_status', 'pending');

        $this->postJson($endpoint, $payment)->assertUnprocessable();
        expect(Payment::query()->count())->toBe(0);

        $this->postJson($endpoint, ['invoice_ids' => $ids, 'action' => 'billed'])->assertNoContent();
        $this->postJson($endpoint, $payment)->assertNoContent();
        $this->postJson($endpoint, $payment)->assertNoContent();
        expect(Payment::query()->count())->toBe(2);

        foreach ($invoices as $invoice) {
            $this->getJson("/api/v1/billing/{$invoice->id}")
                ->assertOk()
                ->assertJsonPath('data.billing_status', 'paid')
                ->assertJsonPath('data.balance_cents', 0);

            $record = $invoice->payments()->sole();
            expect($record->amount_cents)->toBe($invoice->dueCents())
                ->and($record->method)->toBe('cash')
                ->and($record->reference)->toBe('RECEIPT-42')
                ->and($record->paid_on->toDateString())->toBe($expectedDate);
        }
    })->with([
        'date omitted' => [null],
        'past date' => [-7],
        'future date' => [14],
    ]);

    it('uses the Manila delivery date for the same cutoff on the list, document, and export', function (
        string $utcDay,
        array $dates,
    ): void {
        $this->travelTo(Carbon::parse("{$utcDay} 16:30:00 UTC"));
        ($this->haul)(['scheduled_at' => "{$utcDay}T15:30:00Z"]);

        $invoice = Invoice::query()->firstOrFail();
        $cutoff = array_combine(['start', 'end', 'billing_from', 'billing_to', 'payment_target'], $dates);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/billing')
            ->assertOk()
            ->assertJsonPath('data.0.billing_cutoff', $cutoff);
        expect(($this->document)()['billing_cutoff'])->toBe($cutoff);

        $matching = $this->get('/api/v1/billing/export?cutoff='.$cutoff['start'])->assertOk()->streamedContent();
        $other = $this->get('/api/v1/billing/export?cutoff=none')->assertOk()->streamedContent();
        expect($matching)->toContain($invoice->number, implode(',', $cutoff))
            ->and($other)->not->toContain($invoice->number);
    })->with([
        '6 to 15' => ['2026-12-05', ['2026-12-06', '2026-12-15', '2026-12-17', '2026-12-18', '2026-12-28']],
        '16 to 25' => ['2026-12-15', ['2026-12-16', '2026-12-25', '2026-12-27', '2026-12-28', '2027-01-07']],
        '26 to 5' => ['2026-12-25', ['2026-12-26', '2027-01-05', '2027-01-07', '2027-01-08', '2027-01-18']],
        'early in the following month' => ['2027-01-04', ['2026-12-26', '2027-01-05', '2027-01-07', '2027-01-08', '2027-01-18']],
    ]);

    it('says who is billing, with their TIN and their mark', function (): void {
        ($this->haul)();

        $issuer = ($this->document)()['issuer'];

        expect($issuer['name'])->toBe($this->company->name)
            ->and($issuer['tin'])->toBe('008-123-456-000')
            ->and($issuer['address'])->toBe('Iponan, Cagayan de Oro City')
            ->and($issuer['contact_phone'])->toBe('0917 555 0100')
            ->and($issuer['contact_email'])->toBe('accounts@cargorush.ph')
            ->and($issuer)->toHaveKey('logo_url');
    });

    it('says who is being billed, with their TIN and VAT treatment', function (): void {
        ($this->haul)();

        $billTo = ($this->document)()['bill_to'];

        expect($billTo['name'])->toBe('Negros Fresh Mart')
            ->and($billTo['tin'])->toBe('004-987-654-000')
            ->and($billTo['address'])->toBe('Bacolod City, Negros Occidental')
            ->and($billTo['contact'])->toBe('0918 222 3344')
            ->and($billTo['vat_treatment'])->not->toBeNull();
    });

    it('calls itself a delivery invoice, and says what was delivered', function (): void {
        ($this->haul)();

        $document = ($this->document)();

        expect($document['document']['title'])->toBe('Delivery Invoice')
            ->and($document['lines'])->toHaveCount(1);

        $line = $document['lines'][0];

        expect($line['origin'])->toBe('Bacolod')
            ->and($line['destination'])->toBe('Iloilo')
            ->and($line['cargo'])->toBe('Chilled produce, 8 crates')
            ->and($line['weight_kg'])->toBe(1800)
            ->and($line['plate'])->toBe('NCR 4412')
            ->and($line['driver'])->toBe('Marco Reyes')
            ->and($line['reference'])->toStartWith('CTS-')
            ->and($line['delivered_at'])->toBe(now()->toDateString());
    });

    it('carries the five figures and the rates that made them', function (): void {
        ($this->haul)();

        $invoice = Invoice::query()->firstOrFail();
        $totals = ($this->document)()['totals'];

        expect($totals['net_amount_cents'])->toBe($invoice->net_amount_cents)
            ->and($totals['vat_cents'])->toBe($invoice->vat_cents)
            ->and($totals['amount_cents'])->toBe($invoice->net_amount_cents + $invoice->vat_cents)
            ->and($totals['due_cents'])->toBe($invoice->amount_cents - $invoice->withholding_cents)
            ->and($totals['vat_rate_bp'])->toBe($invoice->vat_rate_bp)
            ->and($totals['vat_label'])->not->toBeNull();
    });

    it('writes the amount out in words', function (): void {
        ($this->haul)();

        $invoice = Invoice::query()->firstOrFail();
        $totals = ($this->document)()['totals'];

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
            ->and($document['payments'][0]['reference'])->toBe('BDO-77120')
            ->and($document['payments'][0]['amount_cents'])->toBe(100000)
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

        expect($document['document']['title'])->toBe('Sales Invoice')
            ->and($document['lines'])->toHaveCount(1)
            ->and($document['lines'][0]['description'])->toBe('Freight and hauling services')
            ->and($document['lines'][0]['reference'])->toBeNull();
    });

    it('is refused to an account that cannot see billing', function (): void {
        ($this->haul)();

        $invoice = Invoice::query()->firstOrFail();

        $this->actingAs($this->marco)
            ->getJson("/api/v1/billing/{$invoice->id}/document")
            ->assertForbidden();
    });
});

describe('saving the invoice as a PDF', function (): void {
    it('downloads the invoice as a PDF named after its number', function (): void {
        ($this->haul)();

        $invoice = Invoice::query()->firstOrFail();

        $response = $this->actingAs($this->admin)
            ->get("/api/v1/billing/{$invoice->id}/document.pdf")
            ->assertOk();

        expect($response->headers->get('content-type'))->toBe('application/pdf')
            ->and($response->headers->get('content-disposition'))->toContain($invoice->number.'.pdf')
            ->and(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-');
    });

    it('is refused to an account that cannot see billing', function (): void {
        ($this->haul)();

        $invoice = Invoice::query()->firstOrFail();

        $this->actingAs($this->marco)
            ->get("/api/v1/billing/{$invoice->id}/document.pdf")
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

        expect($csv)->toContain('Number,Direction,Status,Customer,Trip')
            ->and($csv)->toContain('Withholding')
            ->and($csv)->toContain($invoice->number)
            ->and($csv)->toContain('Negros Fresh Mart')
            ->and($csv)->toContain(number_format($invoice->amount_cents / 100, 2, '.', ''));
    });

    it('honours the filters the list was looking at', function (): void {
        ($this->haul)();

        $csv = $this->actingAs($this->admin)
            ->get('/api/v1/billing/export?direction=payable')
            ->assertOk()
            ->streamedContent();

        expect($csv)->toContain('Number,Direction')
            ->and($csv)->not->toContain('Negros Fresh Mart');
    });
});
