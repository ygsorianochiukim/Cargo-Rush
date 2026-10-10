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

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]);

    $this->customer = Customer::create(['name' => 'Metro Grocers', 'contact' => '0917 000 0001']);

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
            ->and($body['amount_cents'])->toBe(1_120_000)
            ->and($body['due_cents'])->toBe(1_120_000)
            ->and($body['vat_rate_bp'])->toBe(1200);
    });

    it('works the VAT backwards when prices are quoted inclusive', function (): void {
        config(['cargo.tax.prices_include_vat' => true]);

        $body = ($this->raise)()->assertCreated()->json('data');

        expect($body['amount_cents'])->toBe(1_000_000)
            ->and($body['net_amount_cents'])->toBe(892_857)
            ->and($body['net_amount_cents'] + $body['vat_cents'])->toBe(1_000_000);
    });

    it('charges nothing to a zero-rated customer, and says which kind', function (): void {
        $this->customer->update(['vat_treatment' => 'zero_rated']);

        $body = ($this->raise)()->assertCreated()->json('data');

        expect($body['vat_cents'])->toBe(0)
            ->and($body['amount_cents'])->toBe(1_000_000)
            ->and($body['vat_treatment'])->toBe('zero_rated')
            ->and($body['vat_label'])->toBe('Zero-rated');
    });

    it('charges nothing at all when the company is not VAT-registered', function (): void {
        $this->company->update(['vat_registered' => false]);

        $body = ($this->raise)()->assertCreated()->json('data');

        expect($body['vat_cents'])->toBe(0)
            ->and($body['amount_cents'])->toBe(1_000_000);
    });

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

    it('is taken from the net, not the gross', function (): void {
        $body = ($this->raise)()->assertCreated()->json('data');

        expect($body['amount_cents'])->toBe(1_120_000)
            ->and($body['withholding_cents'])->toBe(20_000)
            ->and($body['due_cents'])->toBe(1_100_000);
    });

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
            ->assertJsonPath('data.receivable_cents', 720_000)
            ->assertJsonPath('data.collected_cents', 400_000);
    });

    it('buckets what is outstanding by how late it is', function (): void {
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
            ->and($body['by_counterparty'][0]['counterparty'])->toBe('Metro Grocers')
            ->and($body['by_counterparty'][0]['total_cents'])->toBe(2_240_000);
    });

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
