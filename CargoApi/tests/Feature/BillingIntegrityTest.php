<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Billing\Services\BillingService;
use App\Domain\Billing\Services\PaymentService;
use App\Domain\Billing\Services\TaxService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * The places where the money and the documents could drift apart.
 *
 * Each of these was a way to end up with a payment pointing at a document that
 * no longer asked for it, a status the payments contradicted, a statement that
 * never closed, or a dashboard rate measured on two different bases. The fixes
 * are small; what they defend is that every figure the office reads can be
 * reconciled to the allocations beneath it.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]);

    $this->customer = Customer::create(['name' => 'Metro Grocers', 'contact' => '0917 000 0001']);

    // ₱10,000 net, ₱11,200 gross.
    $this->raise = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/billing', [
            'customer_id' => $this->customer->id,
            'issued_at' => now()->toDateString(),
            'due_at' => now()->addDays(30)->toDateString(),
            'amount_cents' => 1_000_000,
            'direction' => 'receivable',
            ...$overrides,
        ])->assertCreated()->json('data');

    $this->pay = fn (string $invoice, int $amount, array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => $amount,
            'paid_on' => now()->toDateString(),
            'method' => 'cheque',
            'reference' => 'CHQ-1',
            'allocations' => [['invoice_id' => $invoice, 'amount_cents' => $amount]],
            ...$overrides,
        ]);

    $this->edit = fn (string $invoice, array $body) => $this->actingAs($this->admin)
        ->patchJson("/api/v1/billing/{$invoice}", $body);

    $this->trip = fn (array $overrides = []) => Trip::create([
        'customer_id' => $this->customer->id,
        'origin' => 'Bacolod',
        'destination' => 'Iloilo',
        'cargo' => 'Chilled produce',
        'weight_kg' => 1800,
        'status' => StatusValue::Delivered->value,
        'scheduled_at' => now(),
        'price_cents' => 1_000_000,
        'currency' => 'PHP',
        ...$overrides,
    ]);
});

describe('cancelling and deleting', function (): void {
    it('refuses to cancel an invoice that has money against it', function (): void {
        $invoice = ($this->raise)();
        ($this->pay)($invoice['id'], 500_000)->assertCreated();

        ($this->edit)($invoice['id'], ['status' => 'cancelled'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        expect(Invoice::findOrFail($invoice['id'])->status)->toBe(StatusValue::Partial);
    });

    it('cancels once the payment has come off', function (): void {
        $invoice = ($this->raise)();
        $payment = ($this->pay)($invoice['id'], 500_000)->json('data.id');

        $this->actingAs($this->admin)->deleteJson("/api/v1/payments/{$payment}")->assertSuccessful();

        ($this->edit)($invoice['id'], ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    });

    it('refuses to delete an invoice that has money against it', function (): void {
        $invoice = ($this->raise)();
        ($this->pay)($invoice['id'], 500_000)->assertCreated();

        $this->actingAs($this->admin)->deleteJson("/api/v1/billing/{$invoice['id']}")
            ->assertUnprocessable();

        expect(Invoice::find($invoice['id']))->not->toBeNull();
    });

    it('will not put a payment against a cancelled invoice', function (): void {
        $invoice = ($this->raise)();
        ($this->edit)($invoice['id'], ['status' => 'cancelled'])->assertOk();

        // It used to be accepted, and the status derived from the money
        // quietly un-cancelled the document.
        ($this->pay)($invoice['id'], 500_000)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations');

        $this->actingAs($this->admin)->postJson("/api/v1/billing/{$invoice['id']}/settle")
            ->assertUnprocessable();

        expect(Invoice::findOrFail($invoice['id'])->status)->toBe(StatusValue::Cancelled)
            ->and(PaymentAllocation::count())->toBe(0);
    });

    it('leaves a cancelled invoice cancelled when a payment comes off it', function (): void {
        // A document cancelled before the guard existed, with money on it.
        $invoice = ($this->raise)();
        $payment = ($this->pay)($invoice['id'], 500_000)->json('data.id');
        Invoice::findOrFail($invoice['id'])->forceFill(['status' => StatusValue::Cancelled->value])->save();

        $this->actingAs($this->admin)->deleteJson("/api/v1/payments/{$payment}")->assertSuccessful();

        expect(Invoice::findOrFail($invoice['id'])->status)->toBe(StatusValue::Cancelled);
    });
});

describe('editing re-derives the status', function (): void {
    it('reopens a paid invoice whose amount went up', function (): void {
        $invoice = ($this->raise)();
        $this->actingAs($this->admin)->postJson("/api/v1/billing/{$invoice['id']}/settle")->assertOk();

        ($this->edit)($invoice['id'], ['amount_cents' => 2_000_000])
            ->assertOk()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.balance_cents', 2_240_000 - 1_120_000);

        expect(Invoice::findOrFail($invoice['id'])->paid_at)->toBeNull();
    });

    it('refuses to lower an invoice below what has been paid', function (): void {
        $invoice = ($this->raise)();
        ($this->pay)($invoice['id'], 800_000)->assertCreated();

        // ₱5,000 net is ₱5,600 gross — less than the ₱8,000 already in.
        ($this->edit)($invoice['id'], ['amount_cents' => 500_000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_cents');

        expect(Invoice::findOrFail($invoice['id'])->amount_cents)->toBe(1_120_000);
    });

    it('does not let the form call a part-paid invoice pending', function (): void {
        $invoice = ($this->raise)();
        ($this->pay)($invoice['id'], 500_000)->assertCreated();

        ($this->edit)($invoice['id'], ['status' => 'pending'])
            ->assertOk()
            ->assertJsonPath('data.status', 'partial');
    });

    it('makes an invoice overdue when its due date moves into the past', function (): void {
        $invoice = ($this->raise)([
            'issued_at' => now()->subDays(40)->toDateString(),
            'due_at' => now()->addDay()->toDateString(),
        ]);

        ($this->edit)($invoice['id'], ['due_at' => now()->subDays(10)->toDateString()])
            ->assertOk()
            ->assertJsonPath('data.status', 'overdue');
    });

    it('reopens a cancelled invoice as whatever the money says', function (): void {
        $invoice = ($this->raise)(['issued_at' => now()->subDays(40)->toDateString(), 'due_at' => now()->subDays(10)->toDateString()]);
        ($this->edit)($invoice['id'], ['status' => 'cancelled'])->assertOk();

        // Editing a cancelled document without mentioning status keeps it so.
        ($this->edit)($invoice['id'], ['payee' => null])->assertOk()->assertJsonPath('data.status', 'cancelled');

        ($this->edit)($invoice['id'], ['status' => 'pending'])
            ->assertOk()
            ->assertJsonPath('data.status', 'overdue');
    });
});

describe('settling', function (): void {
    it('settles once however many times it is pressed', function (): void {
        $invoice = ($this->raise)();

        $this->actingAs($this->admin)->postJson("/api/v1/billing/{$invoice['id']}/settle")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/billing/{$invoice['id']}/settle")->assertOk();

        expect(Payment::count())->toBe(1)
            ->and((int) PaymentAllocation::sum('amount_cents'))->toBe(1_120_000);
    });

    it('refuses a settle against an invoice already paid under it', function (): void {
        $invoice = Invoice::findOrFail(($this->raise)()['id']);
        $stale = Invoice::findOrFail($invoice->id);

        app(BillingService::class)->settle($invoice);

        // A second request holding the row as it was before the first landed:
        // the balance is re-read under the lock, so it is refused rather than
        // recording the money twice.
        expect(fn () => app(PaymentService::class)->settle($stale))
            ->toThrow(ValidationException::class);

        expect(Payment::count())->toBe(1);
    });
});

describe('a trip is invoiced once', function (): void {
    it('is refused by the database, not only by the check before it', function (): void {
        $trip = ($this->trip)();

        $first = app(BillingService::class)->raiseForTrip($trip);
        $again = app(BillingService::class)->raiseForTrip($trip);

        expect($again->id)->toBe($first->id);

        expect(fn () => Invoice::create([
            'customer_id' => $this->customer->id,
            'trip_id' => $trip->id,
            'issued_at' => now()->toDateString(),
            'due_at' => now()->addDays(30)->toDateString(),
            'amount_cents' => 1_000_000,
            'direction' => 'receivable',
            'status' => 'pending',
        ]))->toThrow(UniqueConstraintViolationException::class);
    });

    it('lets any number of hand-raised invoices carry no trip', function (): void {
        ($this->raise)();
        ($this->raise)();

        expect(Invoice::whereNull('trip_id')->count())->toBe(2);
    });

    it('does not re-bill a run whose invoice was deleted', function (): void {
        $trip = ($this->trip)();
        app(BillingService::class)->raiseForTrip($trip)->delete();

        expect(app(BillingService::class)->raiseForTrip($trip))->toBeNull()
            ->and(Invoice::withTrashed()->where('trip_id', $trip->id)->count())->toBe(1);
    });
});

describe('tax arithmetic', function (): void {
    it('rounds VAT and withholding half-up to the centavo', function (): void {
        $this->customer->update(['withholds_tax' => true]);

        // 2% of ₱10,000.25 is ₱200.005 — half a centavo, which rounds up.
        // Truncation said ₱200.00.
        $body = ($this->raise)(['amount_cents' => 1_000_025]);

        expect($body['vat_cents'])->toBe(120_003)
            ->and($body['withholding_cents'])->toBe(20_001);

        // 12% of ₱10,000.05 is ₱1,200.006: ₱1,200.01, not ₱1,200.00.
        expect(($this->raise)(['amount_cents' => 1_000_005])['vat_cents'])->toBe(120_001);
    });

    it('keeps net plus VAT at the quoted all-in price', function (): void {
        $this->actingAs($this->admin)->patchJson('/api/v1/company', ['prices_include_vat' => true])->assertOk();

        // ₱10.00 all-in: 1000 / 1.12 = 892.857 — rounded to 893, not cut to 892.
        $body = ($this->raise)(['amount_cents' => 1_000]);

        expect($body['net_amount_cents'])->toBe(893)
            ->and($body['vat_cents'])->toBe(107)
            ->and($body['amount_cents'])->toBe(1_000);
    });

    it('rounds a negative figure symmetrically', function (): void {
        expect(TaxService::roundDiv(25, 10))->toBe(3)
            ->and(TaxService::roundDiv(-25, 10))->toBe(-3)
            ->and(TaxService::roundDiv(24, 10))->toBe(2);
    });

    it('withholds on the net, VAT excluded', function (): void {
        $this->customer->update(['withholds_tax' => true]);

        $body = ($this->raise)();

        expect($body['withholding_cents'])->toBe(20_000)
            ->and($body['due_cents'])->toBe(1_100_000);
    });
});

describe('the statement of account', function (): void {
    beforeEach(function (): void {
        $this->statement = fn (array $query = []) => $this->actingAs($this->admin)
            ->getJson('/api/v1/billing/statement/'.$this->customer->id.'?'.http_build_query($query))
            ->assertOk()->json('data');
    });

    it('closes at zero on a withholding customer who paid the due', function (): void {
        $this->customer->update(['withholds_tax' => true]);
        $invoice = ($this->raise)();

        $this->actingAs($this->admin)->postJson("/api/v1/billing/{$invoice['id']}/settle")->assertOk();

        $statement = ($this->statement)();
        $kinds = array_column($statement['lines'], 'kind');

        // Charged the gross, credited the withholding, credited the payment.
        expect($kinds)->toBe(['invoice', 'withholding', 'payment'])
            ->and($statement['lines'][1]['credit_cents'])->toBe(20_000)
            ->and($statement['lines'][1]['date'])->toBe($statement['lines'][0]['date'])
            ->and($statement['closing_balance_cents'])->toBe(0)
            ->and($statement['aging']['total_cents'])->toBe(0);
    });

    it('carries the withholding into the opening balance', function (): void {
        $this->customer->update(['withholds_tax' => true]);
        ($this->raise)(['issued_at' => now()->subDays(40)->toDateString(), 'due_at' => now()->subDays(10)->toDateString()]);

        $statement = ($this->statement)(['from' => now()->subDays(5)->toDateString()]);

        expect($statement['opening_balance_cents'])->toBe(1_100_000)
            ->and($statement['closing_balance_cents'])->toBe(1_100_000);
    });

    it('ages against the money received by the statement date, not since', function (): void {
        $invoice = ($this->raise)([
            'issued_at' => now()->subDays(40)->toDateString(),
            'due_at' => now()->subDays(20)->toDateString(),
        ]);
        ($this->pay)($invoice['id'], 1_120_000, ['paid_on' => now()->toDateString()])->assertCreated();

        $statement = ($this->statement)(['to' => now()->subDays(5)->toDateString()]);

        // Still owed at the time — the payment came five days later.
        expect($statement['closing_balance_cents'])->toBe(1_120_000)
            ->and($statement['aging']['total_cents'])->toBe(1_120_000)
            ->and($statement['aging']['days_1_30'])->toBe(1_120_000);
    });
});

describe('the dashboard', function (): void {
    beforeEach(function (): void {
        $this->receivables = fn () => $this->actingAs($this->admin)
            ->getJson('/api/v1/dashboard/receivables')->assertOk()->json('data');
    });

    it('counts late money without waiting for the overdue sweep', function (): void {
        $invoice = ($this->raise)([
            'issued_at' => now()->subDays(40)->toDateString(),
            'due_at' => now()->addDays(5)->toDateString(),
        ]);
        // Its due date passes; nobody runs cargo:invoices-overdue.
        Invoice::findOrFail($invoice['id'])->forceFill(['due_at' => now()->subDays(3)->toDateString()])->save();

        expect(($this->receivables)()['overdue_cents'])->toBe(1_120_000);
    });

    it('counts part-paid invoices as awaiting payment', function (): void {
        $invoice = ($this->raise)();
        ($this->pay)($invoice['id'], 500_000)->assertCreated();
        ($this->raise)();

        expect(($this->receivables)()['pending_count'])->toBe(2);
    });

    it('measures collection on the window, net of withholding', function (): void {
        $this->customer->update(['withholds_tax' => true]);

        // In the window, and paid at its due — fully collected, although
        // ₱200 less arrived than the document says.
        $recent = ($this->raise)();
        $this->actingAs($this->admin)->postJson("/api/v1/billing/{$recent['id']}/settle")->assertOk();

        // Raised long before the window and still unpaid: not this window's
        // collection problem.
        ($this->raise)([
            'issued_at' => now()->subDays(90)->toDateString(),
            'due_at' => now()->subDays(60)->toDateString(),
        ]);

        $collection = ($this->receivables)()['collection'];

        expect($collection['billed_cents'])->toBe(1_100_000)
            ->and($collection['collected_cents'])->toBe(1_100_000)
            ->and($collection['rate_pct'])->toBe(100);
    });

    it('has no rate when nothing was billed in the window', function (): void {
        expect(($this->receivables)()['collection']['rate_pct'])->toBeNull();
    });
});

describe('re-quoting', function (): void {
    it('keeps the rates frozen on the invoice and skips cancelled ones', function (): void {
        $trip = ($this->trip)();
        $cancelled = ($this->trip)();

        // Double-taxed at a 10% rate that applied when it was raised.
        $wrong = Invoice::create([
            'customer_id' => $this->customer->id, 'trip_id' => $trip->id,
            'issued_at' => now()->toDateString(), 'due_at' => now()->addDays(30)->toDateString(),
            'direction' => 'receivable', 'status' => 'pending',
            'net_amount_cents' => 1_100_000, 'vat_cents' => 110_000, 'amount_cents' => 1_210_000,
            'vat_rate_bp' => 1_000, 'withholding_rate_bp' => 0, 'vat_treatment' => 'vatable',
        ]);

        $void = Invoice::create([
            'customer_id' => $this->customer->id, 'trip_id' => $cancelled->id,
            'issued_at' => now()->toDateString(), 'due_at' => now()->addDays(30)->toDateString(),
            'direction' => 'receivable', 'status' => 'cancelled',
            'net_amount_cents' => 1_100_000, 'vat_cents' => 110_000, 'amount_cents' => 1_210_000,
            'vat_rate_bp' => 1_000, 'withholding_rate_bp' => 0, 'vat_treatment' => 'vatable',
        ]);

        $this->artisan('cargo:invoices-requote')->assertSuccessful();

        // Re-based on the trip's ₱10,000 at the frozen 10% — not today's 12%.
        expect($wrong->fresh()->net_amount_cents)->toBe(1_000_000)
            ->and($wrong->fresh()->vat_cents)->toBe(100_000)
            ->and($wrong->fresh()->vat_rate_bp)->toBe(1_000)
            ->and($void->fresh()->amount_cents)->toBe(1_210_000)
            ->and($void->fresh()->status)->toBe(StatusValue::Cancelled);
    });
});
