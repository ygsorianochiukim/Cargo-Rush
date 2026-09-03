<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Invoice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;

/**
 * Money in, from the haul to the payment.
 *
 * Billing used to start with somebody remembering to raise a document, and the
 * Dashboard could only say what was owed because "paid" and "delivered" were
 * the same stored value — so no query could add up money that had actually
 * arrived without also counting every delivered trip.
 *
 * These pin the chain: delivering raises the receivable at the price the trip
 * was quoted at, settling writes `paid` and stamps when, and the Dashboard
 * separates the two.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();
    $this->customer = Customer::where('name', 'Negros Fresh Mart')->firstOrFail();

    /** Book a run for a customer, take it out, and hand it over. */
    $this->haul = function (array $overrides = []): string {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/trips', [
            'origin' => 'Bacolod',
            'destination' => 'Iloilo',
            'cargo' => 'Chilled produce',
            'weight_kg' => 1800,
            'customer_id' => $this->customer->id,
            'driver_id' => $this->driver->id,
            'vehicle_id' => $this->vehicle->id,
            'scheduled_at' => now()->toIso8601String(),
            'status' => StatusValue::Assigned->value,
            ...$overrides,
        ])->json('data.id');

        $this->actingAs($this->marco)->postJson("/api/v1/trips/{$id}/start", [])->assertOk();
        $this->actingAs($this->marco)
            ->postJson('/api/v1/trips/current/deliver', ['receiver_name' => 'R. Uy'])
            ->assertOk();

        return $id;
    };
});

describe('delivering raises the receivable', function (): void {
    it('bills the customer what the trip was quoted at', function (): void {
        expect(Invoice::count())->toBe(0);

        $id = ($this->haul)();
        $trip = Trip::findOrFail($id);
        $invoice = Invoice::firstOrFail();

        expect($invoice->trip_id)->toBe($id)
            ->and($invoice->customer_id)->toBe($this->customer->id)
            ->and($invoice->direction)->toBe(InvoiceDirection::Receivable)
            ->and($invoice->status)->toBe(StatusValue::Pending)
            // The customer is invoiced what they were told, not a figure
            // somebody typed afterwards.
            ->and($invoice->amount_cents)->toBe($trip->price_cents)
            ->and($invoice->number)->toStartWith('INV-');
    });

    it('dates it on the terms in configuration', function (): void {
        ($this->haul)();

        $invoice = Invoice::firstOrFail();
        $terms = (int) config('cargo.billing.terms_days');

        expect($invoice->issued_at->toDateString())->toBe(now()->toDateString())
            ->and($invoice->due_at->toDateString())
            ->toBe(now()->addDays($terms)->toDateString());
    });

    it('raises nothing for the company own freight', function (): void {
        // A run with no customer. There is nobody to bill, and a document
        // addressed to nobody is worse than none — but the delivery, and the
        // income on the sheet, still happen.
        $id = ($this->haul)(['customer_id' => null]);

        expect(Invoice::count())->toBe(0)
            ->and(Trip::findOrFail($id)->status)->toBe(StatusValue::Delivered)
            ->and(Trip::findOrFail($id)->billed_at)->not->toBeNull();
    });

    it('raises exactly one document per haul', function (): void {
        $id = ($this->haul)();

        // The office pressing Complete after the driver already closed it.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/trips/{$id}/complete", ['receiver_name' => 'R. Uy'])
            ->assertStatus(422);

        expect(Invoice::where('trip_id', $id)->count())->toBe(1);
    });

    it('prints the trip reference beside the amount', function (): void {
        $id = ($this->haul)();
        $reference = Trip::findOrFail($id)->reference;

        // Without this the reconciliation is a human matching dates and
        // figures by eye.
        $this->actingAs($this->admin)->getJson('/api/v1/billing')
            ->assertOk()
            ->assertJsonPath('data.0.trip_reference', $reference);
    });

    it('shows up on the customer own invoice list', function (): void {
        $buyer = User::where('email', 'orders@negrosfresh.ph')->firstOrFail();

        ($this->haul)();

        $this->actingAs($buyer)->getJson('/api/v1/portal/invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', StatusValue::Pending->value);
    });
});

describe('settling it', function (): void {
    it('writes paid, not delivered, and stamps when', function (): void {
        ($this->haul)();
        $invoice = Invoice::firstOrFail();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/billing/{$invoice->id}/settle")
            ->assertOk()
            ->assertJsonPath('data.status', StatusValue::Paid->value);

        // `delivered` is the word for a closed-out haul. Sharing it with money
        // meant no page could count what had arrived.
        expect($invoice->fresh()->paid_at)->not->toBeNull();
    });

    it('does not move the date the money arrived when pressed twice', function (): void {
        ($this->haul)();
        $invoice = Invoice::firstOrFail();

        $this->actingAs($this->admin)->postJson("/api/v1/billing/{$invoice->id}/settle")->assertOk();
        $first = $invoice->fresh()->paid_at;

        $this->travel(2)->days();

        $this->actingAs($this->admin)->postJson("/api/v1/billing/{$invoice->id}/settle")->assertOk();

        expect($invoice->fresh()->paid_at->toIso8601String())->toBe($first->toIso8601String());
    });

    it('takes it out of what the customer owes', function (): void {
        ($this->haul)();
        $invoice = Invoice::firstOrFail();

        expect($this->customer->outstandingCents())->toBe($invoice->amount_cents);

        $this->actingAs($this->admin)->postJson("/api/v1/billing/{$invoice->id}/settle")->assertOk();

        // Derived from the invoices every time, so it cannot drift from Billing.
        expect($this->customer->fresh()->outstandingCents())->toBe(0);
    });
});

describe('the dashboard separates owed from collected', function (): void {
    it('counts an unsettled haul as pending payment', function (): void {
        $id = ($this->haul)();
        $price = Trip::findOrFail($id)->price_cents;

        $this->actingAs($this->admin)->getJson('/api/v1/dashboard/receivables')
            ->assertOk()
            ->assertJsonPath('data.pending_payment_cents', $price)
            ->assertJsonPath('data.successful_payment_cents', 0)
            ->assertJsonPath('data.pending_count', 1)
            ->assertJsonPath('data.paid_count', 0)
            // What the fleet earned on the road, which is a different question
            // from when the cash turns up.
            ->assertJsonPath('data.income_cents', $price);
    });

    it('moves it across when the money arrives', function (): void {
        $id = ($this->haul)();
        $price = Trip::findOrFail($id)->price_cents;

        $this->actingAs($this->admin)
            ->postJson('/api/v1/billing/'.Invoice::firstOrFail()->id.'/settle')
            ->assertOk();

        $this->actingAs($this->admin)->getJson('/api/v1/dashboard/receivables')
            ->assertOk()
            ->assertJsonPath('data.pending_payment_cents', 0)
            ->assertJsonPath('data.successful_payment_cents', $price)
            ->assertJsonPath('data.pending_count', 0)
            ->assertJsonPath('data.paid_count', 1)
            // The haul still earned what it earned. Being paid does not
            // change the income; it changes where the money is.
            ->assertJsonPath('data.income_cents', $price);
    });

    it('carries what is late alongside, not deducted from, what is owed', function (): void {
        ($this->haul)();

        Invoice::firstOrFail()->update(['due_at' => now()->subWeek()->toDateString()]);
        $this->artisan('cargo:invoices-overdue');

        $price = Trip::firstOrFail()->price_cents;

        $this->actingAs($this->admin)->getJson('/api/v1/dashboard/receivables')
            ->assertOk()
            // Overdue money is still owed. Chasing it is a different job from
            // expecting it, which is why it is reported as well as, not
            // instead of.
            ->assertJsonPath('data.pending_payment_cents', $price)
            ->assertJsonPath('data.overdue_cents', $price)
            ->assertJsonPath('data.pending_count', 1);
    });

    it('answers on a system where nothing has been delivered', function (): void {
        $this->actingAs($this->admin)->getJson('/api/v1/dashboard/receivables')
            ->assertOk()
            ->assertJsonPath('data.pending_payment_cents', 0)
            ->assertJsonPath('data.successful_payment_cents', 0)
            ->assertJsonPath('data.income_cents', 0);
    });
});
