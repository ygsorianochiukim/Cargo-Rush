<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;

/**
 * What an invoice says, and what actually arrives.
 *
 * These were the same number until now, and in Philippine freight they never
 * are. A ₱10,000 haul is billed at ₱11,200 with VAT; a customer who is a
 * withholding agent keeps back ₱200 — 2% of the net — for the BIR; ₱11,000 lands in
 * the bank. The system knew only the ₱10,000, so the document it printed was
 * not one a VAT-registered customer could accept and the figure it expected
 * never matched the payment.
 *
 * The second half is money arriving. Settling used to be a status flip, which
 * models being paid in full, once, from nowhere in particular — and customers
 * pay in instalments, and settle four invoices with one cheque.
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

    // ₱10,000 net. Every figure below is worked from this one.
    $this->raise = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/billing', [
            'customer_id' => $this->customer->id,
            'issued_at' => now()->toDateString(),
            'due_at' => now()->addDays(30)->toDateString(),
            'amount_cents' => 1_000_000,
            'direction' => 'receivable',
            ...$overrides,
        ]);
});

describe('VAT', function (): void {
    it('adds 12 per cent on top of the quoted haul', function (): void {
        $body = ($this->raise)()->assertCreated()->json('data');

        expect($body['net_amount_cents'])->toBe(1_000_000)
            ->and($body['vat_cents'])->toBe(120_000)
            // What the document says.
            ->and($body['amount_cents'])->toBe(1_120_000)
            // And what the customer remits, since this one does not withhold.
            ->and($body['due_cents'])->toBe(1_120_000)
            ->and($body['vat_rate_bp'])->toBe(1200);
    });

    /**
     * Where the desk quotes an all-in figure, the VAT is inside it.
     *
     * The customer was promised ₱10,000 and must be billed ₱10,000 — so the
     * net is worked backwards rather than the VAT being added on top of a
     * number that already contained it.
     */
    it('works the VAT backwards when prices are quoted inclusive', function (): void {
        config(['cargo.tax.prices_include_vat' => true]);

        $body = ($this->raise)()->assertCreated()->json('data');

        expect($body['amount_cents'])->toBe(1_000_000)
            ->and($body['net_amount_cents'])->toBe(892_857)
            // Net plus VAT is exactly the figure quoted — the rounding
            // remainder goes to VAT rather than being lost.
            ->and($body['net_amount_cents'] + $body['vat_cents'])->toBe(1_000_000);
    });

    /**
     * Zero-rated and exempt both put ₱0 on the invoice and are not the same
     * thing to the filing, which is why the treatment is carried on the row.
     */
    it('charges nothing to a zero-rated customer, and says which kind', function (): void {
        $this->customer->update(['vat_treatment' => 'zero_rated']);

        $body = ($this->raise)()->assertCreated()->json('data');

        expect($body['vat_cents'])->toBe(0)
            ->and($body['amount_cents'])->toBe(1_000_000)
            ->and($body['vat_treatment'])->toBe('zero_rated')
            ->and($body['vat_label'])->toBe('Zero-rated');
    });

    /**
     * A haulier below the registration threshold files percentage tax instead.
     * Putting a VAT line on its invoices would be charging a tax it has no
     * authority to collect.
     */
    it('charges nothing at all when the company is not VAT-registered', function (): void {
        $this->company->update(['vat_registered' => false]);

        $body = ($this->raise)()->assertCreated()->json('data');

        expect($body['vat_cents'])->toBe(0)
            ->and($body['amount_cents'])->toBe(1_000_000);
    });

    /** A supplier's bill arrives with its own tax already on it. */
    it('leaves a payable alone', function (): void {
        $body = ($this->raise)(['direction' => 'payable', 'customer_id' => null, 'payee' => 'Petron'])
            ->assertCreated()->json('data');

        expect($body['vat_cents'])->toBe(0)
            ->and($body['amount_cents'])->toBe(1_000_000);
    });
});

describe('withholding tax', function (): void {
    beforeEach(function (): void {
        $this->customer->update(['withholds_tax' => true]);
    });

    /**
     * The detail most worth getting right.
     *
     * Creditable withholding is a percentage of the income payment **exclusive
     * of VAT** — the VAT is the government's, passing through us, not income
     * of ours to withhold on. Taking 2% of the gross over-withholds on every
     * invoice, and the customer's 2307 never agrees with our figure.
     */
    it('is taken from the net, not the gross', function (): void {
        $body = ($this->raise)()->assertCreated()->json('data');

        expect($body['amount_cents'])->toBe(1_120_000)
            // 2% of 1,000,000, not of 1,120,000.
            ->and($body['withholding_cents'])->toBe(20_000)
            ->and($body['due_cents'])->toBe(1_100_000);
    });

    /**
     * A short payment is not a shortfall.
     *
     * The withheld portion is remitted to the BIR on our behalf, so an invoice
     * paid at its `due` figure is settled in full even though less arrived
     * than the document asked for. Reading that gap as an outstanding balance
     * was the reconciliation error this removes.
     */
    it('settles in full when the customer pays the due figure', function (): void {
        $id = ($this->raise)()->json('data.id');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/billing/$id/settle")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.balance_cents', 0)
            ->assertJsonPath('data.paid_cents', 1_100_000);

        expect(Payment::firstOrFail()->amount_cents)->toBe(1_100_000);
    });
});

describe('payments', function (): void {
    it('records a part payment and leaves the rest owing', function (): void {
        $id = ($this->raise)()->json('data.id');

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 400_000,
            'paid_on' => now()->toDateString(),
            'method' => 'cheque',
            'reference' => 'CHQ-88213',
            'allocations' => [['invoice_id' => $id, 'amount_cents' => 400_000]],
        ])->assertCreated();

        $this->actingAs($this->admin)->getJson("/api/v1/billing/$id")
            ->assertOk()
            // The state the old model could not hold: not pending, not paid.
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.paid_cents', 400_000)
            ->assertJsonPath('data.balance_cents', 720_000);
    });

    it('closes the document when the rest arrives', function (): void {
        $id = ($this->raise)()->json('data.id');

        foreach ([400_000, 720_000] as $amount) {
            $this->actingAs($this->admin)->postJson('/api/v1/payments', [
                'customer_id' => $this->customer->id,
                'amount_cents' => $amount,
                'paid_on' => now()->toDateString(),
                'allocations' => [['invoice_id' => $id, 'amount_cents' => $amount]],
            ])->assertCreated();
        }

        $this->actingAs($this->admin)->getJson("/api/v1/billing/$id")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.balance_cents', 0);
    });

    /**
     * One cheque, four invoices — how a customer actually settles a month.
     *
     * Impossible while payment was a column on the invoice: the cheque had to
     * be split by hand into four fictions.
     */
    it('spreads one payment across several invoices', function (): void {
        $first = ($this->raise)()->json('data.id');
        $second = ($this->raise)()->json('data.id');

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 2_240_000,
            'paid_on' => now()->toDateString(),
            'reference' => 'TT-4471',
            'allocations' => [
                ['invoice_id' => $first, 'amount_cents' => 1_120_000],
                ['invoice_id' => $second, 'amount_cents' => 1_120_000],
            ],
        ])->assertCreated();

        foreach ([$first, $second] as $id) {
            $this->actingAs($this->admin)->getJson("/api/v1/billing/$id")
                ->assertOk()
                ->assertJsonPath('data.status', 'paid');
        }
    });

    /**
     * Money on account, before there is anything to put it against.
     *
     * Refusing to record it until an invoice exists means the bank statement
     * and the system disagree for a fortnight.
     */
    it('accepts a payment with nothing allocated yet', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 500_000,
            'paid_on' => now()->toDateString(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.allocated_cents', 0)
            ->assertJsonPath('data.unallocated_cents', 500_000);
    });

    it('refuses to allocate more than the payment holds', function (): void {
        $id = ($this->raise)()->json('data.id');

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 500_000,
            'paid_on' => now()->toDateString(),
            'allocations' => [['invoice_id' => $id, 'amount_cents' => 800_000]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('allocations');
    });

    /**
     * Overpaying a document does not make it more paid — it hides money that
     * belongs against the customer's next invoice.
     */
    it('refuses to put more against an invoice than it owes', function (): void {
        $id = ($this->raise)()->json('data.id');

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 2_000_000,
            'paid_on' => now()->toDateString(),
            'allocations' => [['invoice_id' => $id, 'amount_cents' => 1_500_000]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('allocations');
    });

    it('refuses a payment dated in the future', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 100_000,
            'paid_on' => now()->addWeek()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('paid_on');
    });

    /**
     * Money entered against the wrong customer is the ordinary reason to
     * un-record one. Leaving the documents marked paid would be the worse
     * half of the mistake.
     */
    it('puts the invoice back when a payment is removed', function (): void {
        $id = ($this->raise)()->json('data.id');

        $paymentId = $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 1_120_000,
            'paid_on' => now()->toDateString(),
            'allocations' => [['invoice_id' => $id, 'amount_cents' => 1_120_000]],
        ])->json('data.id');

        $this->actingAs($this->admin)->getJson("/api/v1/billing/$id")
            ->assertJsonPath('data.status', 'paid');

        $this->actingAs($this->admin)->deleteJson("/api/v1/payments/$paymentId")->assertNoContent();

        $this->actingAs($this->admin)->getJson("/api/v1/billing/$id")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.paid_cents', 0)
            ->assertJsonPath('data.balance_cents', 1_120_000);
    });

    it('dates the settlement from the day the money moved', function (): void {
        $id = ($this->raise)()->json('data.id');
        $cleared = now()->subDays(3)->toDateString();

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 1_120_000,
            'paid_on' => $cleared,
            'allocations' => [['invoice_id' => $id, 'amount_cents' => 1_120_000]],
        ])->assertCreated();

        // Not the moment somebody typed it in — every collections figure is
        // dated from this, and a cheque entered on Friday for money that
        // cleared on Tuesday belongs to Tuesday.
        expect(Invoice::findOrFail($id)->paid_at->toDateString())->toBe($cleared);
    });
});

describe('what is owed, and how late', function (): void {
    it('counts the balance rather than the face value', function (): void {
        $id = ($this->raise)()->json('data.id');

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 400_000,
            'paid_on' => now()->toDateString(),
            'allocations' => [['invoice_id' => $id, 'amount_cents' => 400_000]],
        ])->assertCreated();

        $this->actingAs($this->admin)->getJson('/api/v1/billing/totals')
            ->assertOk()
            // A half-paid invoice is half a receivable. Summing the face value
            // would keep counting money that has already arrived.
            ->assertJsonPath('data.receivable_cents', 720_000)
            ->assertJsonPath('data.collected_cents', 400_000);
    });

    it('buckets what is outstanding by how late it is', function (): void {
        // Due seventy-five days ago, and due next month. Issued earlier still,
        // because a due date cannot precede the day the document was raised.
        $late = ($this->raise)([
            'issued_at' => now()->subDays(105)->toDateString(),
            'due_at' => now()->subDays(75)->toDateString(),
        ])->assertCreated()->json('data.id');

        ($this->raise)()->assertCreated();

        Invoice::findOrFail($late)->update(['status' => StatusValue::Overdue->value]);

        $body = $this->actingAs($this->admin)->getJson('/api/v1/billing/aging')
            ->assertOk()
            ->json('data');

        expect($body['buckets']['61_90'])->toBe(1_120_000)
            ->and($body['buckets']['current'])->toBe(1_120_000)
            ->and($body['total_cents'])->toBe(2_240_000)
            // Worst first: whoever owes the most is the call to make.
            ->and($body['by_counterparty'][0]['counterparty'])->toBe('Metro Grocers')
            ->and($body['by_counterparty'][0]['total_cents'])->toBe(2_240_000);
    });

    /**
     * A part-paid document that has gone past its due date is still late on
     * the rest — and is exactly the invoice most worth chasing.
     */
    it('takes a part-paid invoice overdue too', function (): void {
        $id = ($this->raise)([
            'issued_at' => now()->subDays(40)->toDateString(),
            'due_at' => now()->subWeek()->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 400_000,
            'paid_on' => now()->toDateString(),
            'allocations' => [['invoice_id' => $id, 'amount_cents' => 400_000]],
        ])->assertCreated();

        expect(Invoice::findOrFail($id)->status)->toBe(StatusValue::Partial);

        $this->artisan('cargo:invoices-overdue');

        expect(Invoice::findOrFail($id)->status)->toBe(StatusValue::Overdue);
    });
});

/**
 * A document already issued is not reopened by a rate change.
 *
 * The rates are frozen on the row for the same reason a trip's price is
 * frozen: an invoice is a promise made on a date, and somebody is holding a
 * copy of it.
 */
it('keeps the rates that applied on the day', function (): void {
    $id = ($this->raise)()->json('data.id');

    config(['cargo.tax.vat_rate_bp' => 1400]);

    $this->actingAs($this->admin)->getJson("/api/v1/billing/$id")
        ->assertOk()
        ->assertJsonPath('data.vat_rate_bp', 1200)
        ->assertJsonPath('data.vat_cents', 120_000);
});

it('does not requote the tax on an edit that only moves the due date', function (): void {
    $id = ($this->raise)()->json('data.id');

    config(['cargo.tax.vat_rate_bp' => 1400]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/billing/$id", ['due_at' => now()->addDays(60)->toDateString()])
        ->assertOk()
        ->assertJsonPath('data.vat_cents', 120_000);
});
