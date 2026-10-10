<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use App\Domain\Tenancy\Support\RateBook;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(NavigationSeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => Role::Administrator->value,
    ]);

    $this->setRates = fn (array $rates) => $this->actingAs($this->admin)
        ->patchJson('/api/v1/company', $rates);

    $this->quote = fn (int $km, int $weightKg = 0) => $this->actingAs($this->admin)
        ->postJson('/api/v1/pricing/quote', ['distance_km' => $km, 'weight_kg' => $weightKg]);
});

describe('a firm that has set nothing', function (): void {
    it('answers with the install defaults, and says they are not its own', function (): void {
        $body = $this->actingAs($this->admin)->getJson('/api/v1/company')->assertOk()->json('data');

        expect($body['rates']['trucker_commission_bp'])->toBe(1200)
            ->and($body['rates']['billing_terms_days'])->toBe(30)
            ->and($body['rates']['vat_rate_bp'])->toBe(1200)
            ->and($body['rates']['withholding_rate_bp'])->toBe(200)
            ->and($body['rates']['prices_include_vat'])->toBeFalse();

        expect($body['rate_defaults']['billing_terms_days'])->toBe($body['rates']['billing_terms_days']);

        expect($body['rate_overrides']['billing_terms_days'])->toBeNull()
            ->and($body['rate_overrides']['withholding_rate_bp'])->toBeNull();
    });

    it('starts every partner on twelve per cent', function (): void {
        expect(app(RateBook::class)->for($this->company)->truckerCommissionBp())->toBe(1200);
    });
});

describe('the retired tariff', function (): void {
    it('is no longer on the card, and nothing is priced off it', function (): void {
        $body = $this->actingAs($this->admin)->getJson('/api/v1/company')->assertOk()->json('data');

        expect($body['rates'])->not->toHaveKey('tariff')
            ->and($body['rate_defaults'])->not->toHaveKey('tariff')
            ->and($body['rate_overrides'])->not->toHaveKey('tariff_per_km_cents');

        ($this->quote)(100)->assertOk()
            ->assertJsonPath('data.cents', null)
            ->assertJsonPath('data.needs_zone', true)
            ->assertJsonPath('data.source', 'unzoned');
    });

    it('ignores tariff figures sent to it, and keeps what a firm had set in the column', function (): void {
        $this->company->forceFill(['tariff_per_km_cents' => 4_500])->save();

        ($this->setRates)(['tariff_per_km_cents' => 9_900, 'tariff_base_cents' => 1])->assertOk();

        expect($this->company->refresh()->tariff_per_km_cents)->toBe(4_500)
            ->and($this->company->tariff_base_cents)->toBeNull();

        ($this->quote)(100)->assertOk()->assertJsonPath('data.needs_zone', true);
    });
});

describe('tax', function (): void {
    beforeEach(function (): void {
        $this->customer = Customer::create([
            'name' => 'Metro Grocers',
            'contact' => '0917 000 0001',
            'withholds_tax' => true,
        ]);

        $this->raise = fn () => $this->actingAs($this->admin)
            ->postJson('/api/v1/billing', [
                'customer_id' => $this->customer->id,
                'issued_at' => now()->toDateString(),
                'due_at' => now()->addDays(30)->toDateString(),
                'amount_cents' => 1_000_000,
                'direction' => 'receivable',
            ]);
    });

    it('bills at the rate the office set', function (): void {
        ($this->setRates)(['vat_rate_bp' => 1000])->assertOk();

        $body = ($this->raise)()->assertCreated()->json('data');

        expect($body['vat_rate_bp'])->toBe(1000)
            ->and($body['vat_cents'])->toBe(100_000)
            ->and($body['amount_cents'])->toBe(1_100_000);
    });

    it('stops charging VAT for a firm that says it is not registered', function (): void {
        ($this->setRates)(['vat_registered' => false])->assertOk();

        $body = ($this->raise)()->assertCreated()->json('data');

        expect($body['vat_cents'])->toBe(0)
            ->and($body['amount_cents'])->toBe(1_000_000);
    });

    it('withholds at the firm standing rate where the customer has none', function (): void {
        ($this->setRates)(['withholding_rate_bp' => 500])->assertOk();

        $body = ($this->raise)()->assertCreated()->json('data');

        expect($body['withholding_rate_bp'])->toBe(500)
            ->and($body['withholding_cents'])->toBe(50_000);
    });

    it('lets the customer own rate beat the firm standing one', function (): void {
        ($this->setRates)(['withholding_rate_bp' => 500])->assertOk();
        $this->customer->update(['withholding_rate_bp' => 100]);

        $body = ($this->raise)()->assertCreated()->json('data');

        expect($body['withholding_rate_bp'])->toBe(100);
    });

    it('works the VAT backwards when the firm quotes all-in', function (): void {
        ($this->setRates)(['prices_include_vat' => true])->assertOk();

        $body = ($this->raise)()->assertCreated()->json('data');

        expect($body['amount_cents'])->toBe(1_000_000)
            ->and($body['net_amount_cents'] + $body['vat_cents'])->toBe(1_000_000);
    });
});

describe('the other terms', function (): void {
    it('sets how long a delivered run has to pay', function (): void {
        ($this->setRates)(['billing_terms_days' => 15])->assertOk();

        expect(app(RateBook::class)->for($this->company->refresh())->billingTermsDays())->toBe(15);
    });

    it('sets the cut every partner run is split at', function (): void {
        ($this->setRates)(['trucker_commission_bp' => 1500])
            ->assertOk()
            ->assertJsonPath('data.rates.trucker_commission_bp', 1500);

        expect(app(RateBook::class)->for($this->company->refresh())->truckerCommissionBp())->toBe(1500);
    });
});

describe('what it refuses', function (): void {
    it('refuses a commission that is a typo rather than a negotiation', function (): void {
        ($this->setRates)(['trucker_commission_bp' => 9000])->assertStatus(422);
    });

    it('refuses a negative rate anywhere', function (): void {
        ($this->setRates)(['vat_rate_bp' => -1])->assertStatus(422);
        ($this->setRates)(['billing_terms_days' => -1])->assertStatus(422);
    });

    it('will not let the commission be cleared, because there is nothing under it', function (): void {
        ($this->setRates)(['trucker_commission_bp' => null])->assertStatus(422);
    });

    it('keeps the rates away from an account that cannot manage the company', function (): void {
        $dispatcher = User::create([
            'name' => 'Dispatcher', 'email' => 'dispatch@test.test',
            'password' => 'password', 'role' => Role::Dispatcher->value,
        ]);

        $this->actingAs($dispatcher)->getJson('/api/v1/company')->assertForbidden();
        $this->actingAs($dispatcher)
            ->patchJson('/api/v1/company', ['trucker_commission_bp' => 0])
            ->assertForbidden();
    });
});
