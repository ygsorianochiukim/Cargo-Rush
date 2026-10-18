<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\PaymentSource;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);
    $this->seed(ExpenseCategorySeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]);

    $this->food = ExpenseCategory::where('key', 'food')->firstOrFail();

    $this->card = fn (array $payload = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/payment-sources', [
            'name' => 'BPI Mastercard',
            'kind' => 'credit_card',
            'provider' => 'BPI',
            'last_four' => '4821',
            ...$payload,
        ]);
});

describe('wallets and cards', function (): void {
    it('starts every company with cash', function (): void {
        $cash = PaymentSource::where('kind', 'cash')->get();

        expect($cash)->toHaveCount(1);
        expect($cash->first()->name)->toBe('Cash');
    });

    it('records a credit card with its bank and last four digits', function (): void {
        $response = ($this->card)()->assertCreated();

        expect($response->json('data.kind'))->toBe('credit_card');
        expect($response->json('data.kind_label'))->toBe('Credit card');
        expect($response->json('data.label'))->toBe('BPI Mastercard ···· 4821');
    });

    it('records a Shell fleet card as a fuel card', function (): void {
        ($this->card)(['name' => 'Shell Fleet Card', 'kind' => 'fuel_card', 'provider' => 'Shell', 'last_four' => '0093'])
            ->assertCreated();

        expect(PaymentSource::where('kind', 'fuel_card')->value('provider'))->toBe('Shell');
    });

    it('refuses a full card number and an unknown kind', function (): void {
        ($this->card)(['last_four' => '4111111111111111'])
            ->assertStatus(422)->assertJsonValidationErrors('last_four');

        ($this->card)(['name' => 'Mystery', 'kind' => 'barter'])
            ->assertStatus(422)->assertJsonValidationErrors('kind');
    });

    it('refuses a second card by the same name', function (): void {
        ($this->card)()->assertCreated();
        ($this->card)()->assertStatus(422)->assertJsonValidationErrors('name');
    });

    it('lists what was paid from each one', function (): void {
        $card = ($this->card)()->json('data');

        $this->actingAs($this->admin)->postJson('/api/v1/expenses', [
            'category_id' => $this->food->id,
            'date' => now()->toDateString(),
            'amount_cents' => 50_000,
            'payment_source_id' => $card['id'],
        ])->assertCreated();

        $row = collect($this->actingAs($this->admin)->getJson('/api/v1/payment-sources')->assertOk()->json('data'))
            ->firstWhere('id', $card['id']);

        expect($row['expense_spend_cents'])->toBe(50_000);
        expect($row['spend_cents'])->toBe(50_000);
    });

    it('switches a used card off instead of deleting it', function (): void {
        $card = ($this->card)()->json('data');

        $this->actingAs($this->admin)->postJson('/api/v1/expenses', [
            'category_id' => $this->food->id,
            'date' => now()->toDateString(),
            'amount_cents' => 50_000,
            'payment_source_id' => $card['id'],
        ])->assertCreated();

        $this->actingAs($this->admin)->deleteJson("/api/v1/payment-sources/{$card['id']}")
            ->assertOk()
            ->assertJsonPath('meta.retired', true);

        expect(PaymentSource::find($card['id'])->status->value)->toBe('inactive');
    });

    it('deletes an unused card outright', function (): void {
        $card = ($this->card)()->json('data');

        $this->actingAs($this->admin)->deleteJson("/api/v1/payment-sources/{$card['id']}")->assertNoContent();

        expect(PaymentSource::find($card['id']))->toBeNull();
    });

    it('keeps adding a card to whoever posts to the books', function (): void {
        $dispatcher = User::factory()->create(['role' => Role::Dispatcher]);

        $this->actingAs($dispatcher)->postJson('/api/v1/payment-sources', ['name' => 'X', 'kind' => 'cash'])
            ->assertForbidden();
    });

    it('never shows another company its cards', function (): void {
        $other = Company::create(['name' => 'Southern Freight', 'code' => 'southern-freight']);

        app(Tenant::class)->use($other, fn () => PaymentSource::create(['name' => 'Their Visa', 'kind' => 'credit_card']));

        $names = collect($this->actingAs($this->admin)->getJson('/api/v1/payment-sources')->json('data'))->pluck('name');

        expect($names)->not->toContain('Their Visa');
    });
});

describe('paying for an expense', function (): void {
    it('records which card paid and whether the receipt carries VAT', function (): void {
        $card = ($this->card)()->json('data');

        $response = $this->actingAs($this->admin)->postJson('/api/v1/expenses', [
            'category_id' => $this->food->id,
            'date' => now()->toDateString(),
            'amount_cents' => 112_000,
            'is_vatable' => true,
            'payment_source_id' => $card['id'],
        ])->assertCreated();

        expect($response->json('data.is_vatable'))->toBeTrue();
        expect($response->json('data.vat_cents'))->toBe(12_000);
        expect($response->json('data.payment_source_label'))->toBe('BPI Mastercard ···· 4821');
        expect($response->json('data.payment_source_kind'))->toBe('credit_card');
    });

    it('treats an expense as non-VAT unless ticked', function (): void {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/expenses', [
            'category_id' => $this->food->id,
            'date' => now()->toDateString(),
            'amount_cents' => 112_000,
        ])->assertCreated();

        expect($response->json('data.is_vatable'))->toBeFalse();
        expect($response->json('data.vat_cents'))->toBe(0);
    });

    it('filters the list by VAT and by card', function (): void {
        $card = ($this->card)()->json('data');
        $file = fn (array $extra) => $this->actingAs($this->admin)->postJson('/api/v1/expenses', [
            'category_id' => $this->food->id,
            'date' => now()->toDateString(),
            'amount_cents' => 10_000,
            ...$extra,
        ])->assertCreated();

        $file(['is_vatable' => true, 'payment_source_id' => $card['id']]);
        $file(['is_vatable' => false]);

        expect($this->actingAs($this->admin)->getJson('/api/v1/expenses?vatable=1')->json('data'))->toHaveCount(1);
        expect($this->actingAs($this->admin)->getJson('/api/v1/expenses?vatable=0')->json('data'))->toHaveCount(1);
        expect($this->actingAs($this->admin)->getJson("/api/v1/expenses?payment_source_id={$card['id']}")->json('data'))->toHaveCount(1);
    });

    it('splits the month into VAT and non-VAT spend on the report', function (): void {
        $file = fn (int $cents, bool $vat) => $this->actingAs($this->admin)->postJson('/api/v1/expenses', [
            'category_id' => $this->food->id,
            'date' => now()->toDateString(),
            'amount_cents' => $cents,
            'is_vatable' => $vat,
        ])->assertCreated();

        $file(112_000, true);
        $file(30_000, false);

        $report = $this->actingAs($this->admin)->getJson('/api/v1/expenses/report')->assertOk()->json('data');

        expect($report['vatable_cents'])->toBe(112_000);
        expect($report['non_vatable_cents'])->toBe(30_000);
        expect($report['input_vat_cents'])->toBe(12_000);
    });

    it('refuses another company\'s card', function (): void {
        $other = Company::create(['name' => 'Southern Freight', 'code' => 'southern-freight']);
        $theirs = app(Tenant::class)->use($other, fn () => PaymentSource::create(['name' => 'Their Visa', 'kind' => 'credit_card']));

        $this->actingAs($this->admin)->postJson('/api/v1/expenses', [
            'category_id' => $this->food->id,
            'date' => now()->toDateString(),
            'amount_cents' => 10_000,
            'payment_source_id' => $theirs->id,
        ])->assertStatus(422)->assertJsonValidationErrors('payment_source_id');

        expect(Expense::count())->toBe(0);
    });
});

describe('how often an expense is paid', function (): void {
    beforeEach(function (): void {
        $this->file = fn (int $cents, ?string $schedule = null) => $this->actingAs($this->admin)->postJson('/api/v1/expenses', [
            'category_id' => $this->food->id,
            'date' => now()->toDateString(),
            'amount_cents' => $cents,
            ...($schedule === null ? [] : ['pay_schedule' => $schedule]),
        ])->assertCreated();
    });

    it('defaults to cash on receipt', function (): void {
        ($this->file)(10_000)
            ->assertJsonPath('data.pay_schedule', 'on_receipt')
            ->assertJsonPath('data.pay_schedule_label', 'Cash on receipt');
    });

    it('records a monthly or per-cutoff expense and filters the list by it', function (): void {
        ($this->file)(10_000, 'monthly');
        ($this->file)(20_000, 'cutoff');
        ($this->file)(30_000, 'cutoff');
        ($this->file)(40_000);

        $count = fn (string $schedule) => count($this->actingAs($this->admin)
            ->getJson("/api/v1/expenses?pay_schedule={$schedule}")->assertOk()->json('data'));

        expect($count('monthly'))->toBe(1)
            ->and($count('cutoff'))->toBe(2)
            ->and($count('on_receipt'))->toBe(1);

        $split = collect($this->actingAs($this->admin)->getJson('/api/v1/expenses/report')->json('data.by_schedule'))
            ->pluck('amount_cents', 'schedule')
            ->all();

        expect($split)->toBe(['monthly' => 10_000, 'cutoff' => 50_000, 'on_receipt' => 40_000]);
    });

    it('refuses a schedule it does not know', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/expenses', [
            'category_id' => $this->food->id,
            'date' => now()->toDateString(),
            'amount_cents' => 10_000,
            'pay_schedule' => 'weekly',
        ])->assertStatus(422)->assertJsonValidationErrors('pay_schedule');
    });
});

describe('paying for fuel', function (): void {
    it('records the fuel card a fill was paid with', function (): void {
        $shell = ($this->card)(['name' => 'Shell Fleet Card', 'kind' => 'fuel_card', 'provider' => 'Shell', 'last_four' => '0093'])
            ->json('data');

        $vehicle = Vehicle::create([
            'plate' => 'MAR1390', 'model' => 'Isuzu Forward', 'registration_no' => 'R-1',
            'capacity_kg' => 8000, 'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/fuel', [
            'vehicle_id' => $vehicle->id,
            'litres' => 40,
            'amount_cents' => 260_000,
            'odometer_km' => 1000,
            'receipt_no' => 'SH-1',
            'logged_at' => now()->toIso8601String(),
            'payment_source_id' => $shell['id'],
        ])->assertCreated();

        expect($response->json('data.payment_source_label'))->toBe('Shell Fleet Card ···· 0093');
        expect(FuelRecord::firstOrFail()->payment_source_id)->toBe($shell['id']);
    });
});
