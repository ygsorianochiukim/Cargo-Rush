<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->customer = Customer::where('name', 'Negros Fresh Mart')->firstOrFail();

    $this->customer->update(['withholds_tax' => false]);

    $this->raise = fn (int $baseCents) => $this->actingAs($this->admin)->postJson('/api/v1/billing', [
        'direction' => 'receivable',
        'customer_id' => $this->customer->id,
        'amount_cents' => $baseCents,
        'issued_at' => now()->toDateString(),
        'due_at' => now()->addDays(30)->toDateString(),
    ])->assertCreated()->json('data');
});

describe('VAT is added once', function (): void {
    it('quotes a new invoice from the figure the desk typed', function (): void {
        $invoice = ($this->raise)(753000);

        expect($invoice['net_amount_cents'])->toBe(753000)
            ->and($invoice['vat_cents'])->toBe(90360)
            ->and($invoice['amount_cents'])->toBe(843360)
            ->and($invoice['taxable_base_cents'])->toBe(753000);
    });

    it('leaves the figures alone when an edit changes nothing else', function (): void {
        $invoice = ($this->raise)(753000);

        $saved = $this->actingAs($this->admin)
            ->patchJson("/api/v1/billing/{$invoice['id']}", [
                'amount_cents' => $invoice['taxable_base_cents'],
            ])
            ->assertOk()
            ->json('data');

        expect($saved['net_amount_cents'])->toBe(753000)
            ->and($saved['vat_cents'])->toBe(90360)
            ->and($saved['amount_cents'])->toBe(843360);
    });

    it('does not compound over repeated saves', function (): void {
        $invoice = ($this->raise)(753000);

        foreach (range(1, 3) as $ignored) {
            $invoice = $this->actingAs($this->admin)
                ->patchJson("/api/v1/billing/{$invoice['id']}", [
                    'amount_cents' => $invoice['taxable_base_cents'],
                ])
                ->assertOk()
                ->json('data');
        }

        expect($invoice['amount_cents'])->toBe(843360);
    });

    it('re-quotes when the money actually moves', function (): void {
        $invoice = ($this->raise)(753000);

        $corrected = $this->actingAs($this->admin)
            ->patchJson("/api/v1/billing/{$invoice['id']}", ['amount_cents' => 800000])
            ->assertOk()
            ->json('data');

        expect($corrected['net_amount_cents'])->toBe(800000)
            ->and($corrected['vat_cents'])->toBe(96000)
            ->and($corrected['amount_cents'])->toBe(896000);
    });

    it('leaves the tax untouched when only a date is corrected', function (): void {
        $invoice = ($this->raise)(753000);

        $moved = $this->actingAs($this->admin)
            ->patchJson("/api/v1/billing/{$invoice['id']}", [
                'due_at' => now()->addDays(45)->toDateString(),
            ])
            ->assertOk()
            ->json('data');

        expect($moved['amount_cents'])->toBe(843360)
            ->and($moved['vat_cents'])->toBe(90360);
    });
});

describe('money is counted from the payments', function (): void {
    it('will not let a status claim money that has not arrived', function (): void {
        $invoice = ($this->raise)(753000);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/billing/{$invoice['id']}", ['status' => StatusValue::Paid->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        expect(Invoice::findOrFail($invoice['id'])->status)->toBe(StatusValue::Pending);
    });

    it('reports what the customer has actually paid, not what is flagged', function (): void {
        $invoice = ($this->raise)(753000);

        Invoice::findOrFail($invoice['id'])->forceFill([
            'status' => StatusValue::Paid->value,
            'paid_at' => now(),
        ])->save();

        expect($this->customer->paidCents())->toBe(0)
            ->and($this->customer->outstandingCents())->toBe(843360);
    });

    it('counts a payment when there is one', function (): void {
        $invoice = ($this->raise)(753000);

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 843360,
            'paid_on' => now()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'BDO-90210',
            'allocations' => [['invoice_id' => $invoice['id'], 'amount_cents' => 843360]],
        ])->assertCreated();

        expect($this->customer->paidCents())->toBe(843360)
            ->and($this->customer->outstandingCents())->toBe(0)
            ->and(Invoice::findOrFail($invoice['id'])->status)->toBe(StatusValue::Paid);
    });

    it('settles in one press, and leaves the payment behind it', function (): void {
        $invoice = ($this->raise)(753000);

        $settled = $this->actingAs($this->admin)
            ->postJson("/api/v1/billing/{$invoice['id']}/settle", [
                'method' => 'cash',
                'reference' => 'CR-8891',
            ])
            ->assertOk()
            ->json('data');

        expect($settled['status'])->toBe(StatusValue::Paid->value)
            ->and($settled['paid_cents'])->toBe(843360)
            ->and($settled['balance_cents'])->toBe(0)
            ->and($this->customer->paidCents())->toBe(843360);
    });

    it('sees a part payment as part paid', function (): void {
        $invoice = ($this->raise)(753000);

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 300000,
            'paid_on' => now()->toDateString(),
            'method' => 'cash',
            'allocations' => [['invoice_id' => $invoice['id'], 'amount_cents' => 300000]],
        ])->assertCreated();

        expect($this->customer->paidCents())->toBe(300000)
            ->and($this->customer->outstandingCents())->toBe(543360);
    });

    it('shows the customer the same two figures', function (): void {
        $invoice = ($this->raise)(753000);

        $this->actingAs($this->admin)->postJson('/api/v1/payments', [
            'customer_id' => $this->customer->id,
            'amount_cents' => 300000,
            'paid_on' => now()->toDateString(),
            'method' => 'cash',
            'allocations' => [['invoice_id' => $invoice['id'], 'amount_cents' => 300000]],
        ])->assertCreated();

        $buyer = User::where('email', 'orders@negrosfresh.ph')->firstOrFail();

        $summary = $this->actingAs($buyer)->getJson('/api/v1/portal/summary')
            ->assertOk()
            ->json('data');

        expect($summary['successful_payment_cents'])->toBe(300000)
            ->and($summary['pending_payment_cents'])->toBe(543360);
    });
});
