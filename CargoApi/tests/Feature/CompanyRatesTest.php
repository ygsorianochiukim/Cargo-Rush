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

/**
 * The rates an administrator may move, and what moves with them.
 *
 * Until now these were environment variables: the tariff, the payment terms,
 * the tax rates and the partner commission were one set of figures for every
 * haulier on the install, and correcting one meant a deployment. They are
 * columns on the company now, null meaning "the install default", and the
 * whole of the interesting behaviour is in that null — a firm that has never
 * opened the settings card must be quoted, billed and taxed exactly as it was
 * before the card existed.
 *
 * So these tests are in two halves. The first is that nothing changed for
 * somebody who changes nothing. The second is that each setting reaches the
 * arithmetic it is supposed to reach — a tariff that does not move a quote is
 * a number on a form and nothing else, and that is the failure worth pinning,
 * because it looks exactly like success on screen.
 *
 * What a *settled* document does when a rate moves is pinned elsewhere and
 * deliberately not repeated here: an invoice freezes its tax rates
 * (`TaxAndPaymentsTest`) and a delivered run freezes its commission
 * (`TruckerWalletTest`). Moving a rate is always about future work.
 */
beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(NavigationSeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => Role::Administrator->value,
    ]);

    /** One PATCH on the company — the settings card's only write. */
    $this->setRates = fn (array $rates) => $this->actingAs($this->admin)
        ->patchJson('/api/v1/company', $rates);

    /**
     * What the fallback tariff would charge for a run.
     *
     * The install has no rate card in these tests, so every quote falls
     * through to the tariff — which is the figure this screen edits, and the
     * only one of them that can be read back without raising a document.
     */
    $this->quote = fn (int $km, int $weightKg = 0) => $this->actingAs($this->admin)
        ->postJson('/api/v1/pricing/quote', ['distance_km' => $km, 'weight_kg' => $weightKg]);
});

describe('a firm that has set nothing', function (): void {
    it('answers with the install defaults, and says they are not its own', function (): void {
        $body = $this->actingAs($this->admin)->getJson('/api/v1/company')->assertOk()->json('data');

        // In force: a complete set of concrete figures, whatever the columns
        // hold. A settings screen never has to decide what a null means.
        expect($body['rates']['trucker_commission_bp'])->toBe(1200)
            ->and($body['rates']['tariff']['per_km_cents'])->toBe(3_500)
            ->and($body['rates']['billing_terms_days'])->toBe(30)
            ->and($body['rates']['vat_rate_bp'])->toBe(1200)
            ->and($body['rates']['withholding_rate_bp'])->toBe(200)
            ->and($body['rates']['prices_include_vat'])->toBeFalse();

        // And the same figures again as the install's answer, so the card can
        // show what the firm would be departing from.
        expect($body['rate_defaults']['tariff'])->toBe($body['rates']['tariff']);

        // Nothing chosen. This is the field that tells "₱35 because we chose
        // ₱35" from "₱35 because nobody has chosen anything" — identical in a
        // number field, different the day the install default moves.
        expect($body['rate_overrides']['tariff_per_km_cents'])->toBeNull()
            ->and($body['rate_overrides']['billing_terms_days'])->toBeNull();
    });

    it('starts every partner on twelve per cent', function (): void {
        // The column's default, and the one figure here that is not nullable:
        // there is no "unset" commission to fall back from. It used to be
        // overridable per partner from a field on the partner's own screen,
        // and that field and its endpoint are both gone.
        expect(app(RateBook::class)->for($this->company)->truckerCommissionBp())->toBe(1200);
    });
});

describe('the tariff', function (): void {
    it('moves what an off-card run is quoted', function (): void {
        // The install tariff: ₱1,500 + ₱35/km. A 100 km run is ₱5,000.
        expect(($this->quote)(100)->assertOk()->json('data.cents'))->toBe(500_000);

        ($this->setRates)([
            'tariff_base_cents' => 200_000,
            'tariff_per_km_cents' => 4_500,
        ])->assertOk();

        // ₱2,000 + ₱45 × 100 = ₱6,500.
        expect(($this->quote)(100)->assertOk()->json('data.cents'))->toBe(650_000);
    });

    it('floors a short run at the minimum the firm set', function (): void {
        ($this->setRates)([
            'tariff_base_cents' => 0,
            'tariff_per_km_cents' => 1_000,
            'tariff_minimum_cents' => 250_000,
        ])->assertOk();

        // ₱10 a kilometre over 5 km is ₱50, and the firm does not turn a wheel
        // for less than ₱2,500.
        expect(($this->quote)(5)->assertOk()->json('data.cents'))->toBe(250_000);
    });

    it('takes each figure on its own, so correcting one leaves the rest alone', function (): void {
        ($this->setRates)(['tariff_per_km_cents' => 4_500])->assertOk();

        $rates = $this->actingAs($this->admin)->getJson('/api/v1/company')->json('data.rates.tariff');

        expect($rates['per_km_cents'])->toBe(4_500)
            // Untouched, and still the install's rather than zero — the bug a
            // PUT-shaped settings form produces on its first save.
            ->and($rates['base_cents'])->toBe(150_000)
            ->and($rates['per_kg_cents'])->toBe(200);
    });

    it('goes back to the install default when the figure is cleared', function (): void {
        ($this->setRates)(['tariff_per_km_cents' => 4_500])->assertOk();
        ($this->setRates)(['tariff_per_km_cents' => null])->assertOk();

        $body = $this->actingAs($this->admin)->getJson('/api/v1/company')->json('data');

        expect($body['rates']['tariff']['per_km_cents'])->toBe(3_500)
            ->and($body['rate_overrides']['tariff_per_km_cents'])->toBeNull();

        expect(($this->quote)(100)->assertOk()->json('data.cents'))->toBe(500_000);
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

        // Not a rate of zero on a VAT invoice — no VAT at all, which is what a
        // haulier below the registration threshold must issue.
        expect($body['vat_cents'])->toBe(0)
            ->and($body['amount_cents'])->toBe(1_000_000);
    });

    it('withholds at the firm standing rate where the customer has none', function (): void {
        ($this->setRates)(['withholding_rate_bp' => 500])->assertOk();

        $body = ($this->raise)()->assertCreated()->json('data');

        // 5% of the gross, VAT included — ₱11,200 × 5%.
        expect($body['withholding_rate_bp'])->toBe(500)
            ->and($body['withholding_cents'])->toBe(56_000);
    });

    it('lets the customer own rate beat the firm standing one', function (): void {
        ($this->setRates)(['withholding_rate_bp' => 500])->assertOk();
        $this->customer->update(['withholding_rate_bp' => 100]);

        $body = ($this->raise)()->assertCreated()->json('data');

        // Whether a particular shipper withholds, and at what, is a fact about
        // that shipper. The firm's figure is only what to assume for the ones
        // nobody has recorded one against.
        expect($body['withholding_rate_bp'])->toBe(100);
    });

    it('works the VAT backwards when the firm quotes all-in', function (): void {
        ($this->setRates)(['prices_include_vat' => true])->assertOk();

        $body = ($this->raise)()->assertCreated()->json('data');

        // The customer was promised ₱10,000 and is billed ₱10,000, with the
        // VAT taken back out of it rather than added on top.
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
        ($this->setRates)(['tariff_per_km_cents' => -100])->assertStatus(422);
        ($this->setRates)(['vat_rate_bp' => -1])->assertStatus(422);
        ($this->setRates)(['billing_terms_days' => -1])->assertStatus(422);
    });

    it('will not let the commission be cleared, because there is nothing under it', function (): void {
        // Unlike the tariff, which falls back to the install. The column is not
        // nullable and 12% is its default, so a null here is a mistake rather
        // than "use the default".
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
