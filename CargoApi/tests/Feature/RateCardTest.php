<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Shared\Enums\Role;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\NavigationSeeder;

/**
 * The rate card: a zone, its brackets, and what diesel does to them.
 *
 * The thing worth pinning here is not the arithmetic — it is the fallbacks. A
 * destination no zone claims, a zone with a gap in its card, an install that
 * has never recorded a pump price: each of those has to produce a defensible
 * figure rather than a zero, because a zero reaches the ledger as revenue the
 * business never earned and nobody notices until the quarter is closed.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);

    $this->accountant = User::factory()->create(['role' => Role::Accountant]);

    // A card drawn at ₱65.00/L diesel: ₱1,500 within 20 km, ₱2,800 to 80 km,
    // ₱5,000 beyond, all plus per-km and a peso a kilo.
    $this->card = [
        'name' => 'Davao City',
        'code' => 'davao-city',
        'aliases' => ['Davao', 'DVO'],
        'diesel_baseline_cents' => 6_500,
        'brackets' => [
            [
                'label' => 'Within city',
                'min_km' => 0,
                'max_km' => 20,
                'base_cents' => 150_000,
                'per_km_cents' => 3_500,
                'per_kg_cents' => 100,
                'minimum_cents' => 150_000,
            ],
            [
                'label' => 'Outskirts',
                'min_km' => 20,
                'max_km' => 80,
                'base_cents' => 280_000,
                'per_km_cents' => 3_000,
                'per_kg_cents' => 100,
                'minimum_cents' => 280_000,
            ],
            [
                'label' => 'Long haul',
                'min_km' => 80,
                'max_km' => null,
                'base_cents' => 500_000,
                'per_km_cents' => 2_600,
                'per_kg_cents' => 100,
                'minimum_cents' => 500_000,
            ],
        ],
    ];

    $this->createCard = fn (array $overrides = []) => $this->actingAs($this->accountant)
        ->postJson('/api/v1/pricing/zones', [...$this->card, ...$overrides]);

    $this->quote = fn (array $payload) => $this->actingAs($this->accountant)
        ->postJson('/api/v1/pricing/quote', $payload);
});

describe('the zone editor', function (): void {
    it('saves a zone and its whole card in one request', function (): void {
        $response = ($this->createCard)()->assertCreated();

        expect($response->json('data.brackets'))->toHaveCount(3);
        // Position comes from the order sent, not from a field the client has
        // to maintain alongside it.
        expect($response->json('data.brackets.0.label'))->toBe('Within city');
        expect($response->json('data.brackets.0.range'))->toBe('Within 20 km');
        expect($response->json('data.brackets.2.range'))->toBe('80 km and beyond');
    });

    it('keeps bracket ids across an edit, so a priced trip keeps its trace', function (): void {
        $zone = ($this->createCard)()->json('data');
        $firstId = $zone['brackets'][0]['id'];

        $edited = collect($zone['brackets'])
            ->map(fn (array $b): array => [...$b, 'base_cents' => $b['base_cents'] + 10_000])
            ->all();

        $response = $this->actingAs($this->accountant)
            ->patchJson("/api/v1/pricing/zones/{$zone['id']}", ['brackets' => $edited])
            ->assertOk();

        expect($response->json('data.brackets.0.id'))->toBe($firstId);
        expect($response->json('data.brackets.0.base_cents'))->toBe(160_000);
    });

    it('drops only the brackets the card no longer mentions', function (): void {
        $zone = ($this->createCard)()->json('data');

        $response = $this->actingAs($this->accountant)
            ->patchJson("/api/v1/pricing/zones/{$zone['id']}", [
                'brackets' => [$zone['brackets'][0]],
            ])
            ->assertOk();

        expect($response->json('data.brackets'))->toHaveCount(1);
    });

    it('leaves the card alone when an edit does not mention it', function (): void {
        $zone = ($this->createCard)()->json('data');

        $response = $this->actingAs($this->accountant)
            ->patchJson("/api/v1/pricing/zones/{$zone['id']}", ['name' => 'Davao Metro'])
            ->assertOk();

        expect($response->json('data.name'))->toBe('Davao Metro');
        expect($response->json('data.brackets'))->toHaveCount(3);
    });

    it('refuses two brackets that claim the same distance', function (): void {
        ($this->createCard)([
            'brackets' => [
                ['label' => 'A', 'min_km' => 0, 'max_km' => 30, 'base_cents' => 100_000],
                ['label' => 'B', 'min_km' => 20, 'max_km' => 60, 'base_cents' => 200_000],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('brackets.1.min_km');
    });

    it('accepts brackets that sit flush, because ranges are half-open', function (): void {
        ($this->createCard)([
            'brackets' => [
                ['label' => 'A', 'min_km' => 0, 'max_km' => 20, 'base_cents' => 100_000],
                ['label' => 'B', 'min_km' => 20, 'max_km' => 60, 'base_cents' => 200_000],
            ],
        ])->assertCreated();
    });

    it('refuses a bracket that ends before it starts', function (): void {
        ($this->createCard)([
            'brackets' => [
                ['label' => 'Backwards', 'min_km' => 40, 'max_km' => 10, 'base_cents' => 100_000],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('brackets.0.max_km');
    });
});

describe('quoting from the card', function (): void {
    it('matches a zone by alias and prices from the right bracket', function (): void {
        ($this->createCard)();

        $quote = ($this->quote)([
            'destination' => 'Bajada, DVO',
            'distance_km' => 34,
            'weight_kg' => 500,
        ])->assertOk();

        expect($quote->json('data.source'))->toBe('zone');
        expect($quote->json('data.zone.name'))->toBe('Davao City');
        expect($quote->json('data.bracket.label'))->toBe('Outskirts');
        // 280,000 + 34 * 3,000 + 500 * 100 = 432,000
        expect($quote->json('data.card_cents'))->toBe(432_000);
    });

    it('charges part-kilometres as whole ones, the way a fare does', function (): void {
        ($this->createCard)();

        $quote = ($this->quote)(['destination' => 'Davao', 'distance_m' => 1_200])->assertOk();

        // 1.2 km bills as 2: 150,000 + 2 * 3,500 = 157,000
        expect($quote->json('data.km'))->toBe(2);
        expect($quote->json('data.card_cents'))->toBe(157_000);
    });

    it('falls back to the config tariff for a place no zone claims', function (): void {
        ($this->createCard)();

        $quote = ($this->quote)([
            'destination' => 'Zamboanga',
            'distance_km' => 34,
        ])->assertOk();

        expect($quote->json('data.source'))->toBe('tariff');
        expect($quote->json('data.zone'))->toBeNull();
        // The pre-existing formula, untouched: 150,000 + 34 * 3,500.
        expect($quote->json('data.cents'))->toBe(269_000);
    });

    it('falls back to the tariff when the card has a gap at this distance', function (): void {
        ($this->createCard)([
            'brackets' => [
                ['label' => 'Near', 'min_km' => 0, 'max_km' => 20, 'base_cents' => 150_000],
                ['label' => 'Far', 'min_km' => 100, 'max_km' => null, 'base_cents' => 500_000],
            ],
        ]);

        $quote = ($this->quote)(['destination' => 'Davao City', 'distance_km' => 50])->assertOk();

        expect($quote->json('data.source'))->toBe('tariff');
        // The zone is still named, so the office can see the *bracket* was what
        // was missing rather than hunting for a matching problem.
        expect($quote->json('data.zone.name'))->toBe('Davao City');
    });

    it('prefers the more specific zone when two both match', function (): void {
        ($this->createCard)();
        ($this->createCard)([
            'name' => 'Davao del Norte',
            'code' => 'davao-del-norte',
            'aliases' => ['Davao del Norte'],
            'brackets' => [
                ['label' => 'Province', 'min_km' => 0, 'max_km' => null, 'base_cents' => 900_000],
            ],
        ]);

        $quote = ($this->quote)(['destination' => 'Tagum, Davao del Norte'])->assertOk();

        expect($quote->json('data.zone.name'))->toBe('Davao del Norte');
    });

    it('ignores a zone that has been switched off', function (): void {
        $zone = ($this->createCard)()->json('data');

        $this->actingAs($this->accountant)
            ->patchJson("/api/v1/pricing/zones/{$zone['id']}", ['status' => 'inactive'])
            ->assertOk();

        expect(($this->quote)(['destination' => 'Davao City'])->json('data.source'))->toBe('tariff');
    });
});

describe('diesel moves the card', function (): void {
    it('applies no adjustment until a pump price is recorded', function (): void {
        ($this->createCard)();

        $quote = ($this->quote)(['destination' => 'Davao City', 'distance_km' => 10])->assertOk();

        expect($quote->json('data.fuel_adjustment_bp'))->toBe(0);
        expect($quote->json('data.cents'))->toBe($quote->json('data.card_cents'));
    });

    it('adds a surcharge when the pump is above the card baseline', function (): void {
        ($this->createCard)();

        // ₱71.50 against a ₱65.00 baseline: a 10% move, passed through at the
        // 0.35 fuel share, is +350 bp.
        $this->actingAs($this->accountant)
            ->postJson('/api/v1/pricing/diesel', ['price_per_litre_cents' => 7_150])
            ->assertCreated();

        $quote = ($this->quote)(['destination' => 'Davao City', 'distance_km' => 10])->assertOk();

        expect($quote->json('data.fuel_adjustment_bp'))->toBe(350);
        // 185,000 card + 3.5% = 191,475
        expect($quote->json('data.card_cents'))->toBe(185_000);
        expect($quote->json('data.cents'))->toBe(191_475);
        expect($quote->json('data.fuel_adjustment_cents'))->toBe(6_475);
    });

    it('discounts when the pump falls below the baseline', function (): void {
        ($this->createCard)();

        $this->actingAs($this->accountant)
            ->postJson('/api/v1/pricing/diesel', ['price_per_litre_cents' => 5_850])
            ->assertCreated();

        expect(($this->quote)(['destination' => 'Davao City'])->json('data.fuel_adjustment_bp'))
            ->toBe(-350);
    });

    it('caps the swing however far the pump moves', function (): void {
        ($this->createCard)();

        // Triple the baseline. Uncapped that is +7,000 bp; the guard rail is
        // 2,500, and a quote nobody can explain is worse than a stale card.
        $this->actingAs($this->accountant)
            ->postJson('/api/v1/pricing/diesel', ['price_per_litre_cents' => 19_500])
            ->assertCreated();

        expect(($this->quote)(['destination' => 'Davao City'])->json('data.fuel_adjustment_bp'))
            ->toBe(2_500);

        expect($this->actingAs($this->accountant)->getJson('/api/v1/pricing/diesel')->json('data.capped'))
            ->toBeTrue();
    });

    it('does not quote from a price that has not taken effect yet', function (): void {
        ($this->createCard)();

        $this->actingAs($this->accountant)->postJson('/api/v1/pricing/diesel', [
            'price_per_litre_cents' => 9_000,
            'effective_on' => now()->addWeek()->toDateString(),
        ])->assertCreated();

        expect(($this->quote)(['destination' => 'Davao City'])->json('data.fuel_adjustment_bp'))->toBe(0);
    });

    it('corrects the day rather than storing two prices for it', function (): void {
        $today = now()->toDateString();

        foreach ([7_000, 6_800] as $price) {
            $this->actingAs($this->accountant)->postJson('/api/v1/pricing/diesel', [
                'price_per_litre_cents' => $price,
                'effective_on' => $today,
            ])->assertCreated();
        }

        $history = $this->actingAs($this->accountant)->getJson('/api/v1/pricing/diesel')->json('data.history');

        expect($history)->toHaveCount(1);
        expect($history[0]['price_per_litre_cents'])->toBe(6_800);
    });
});

describe('a booked trip', function (): void {
    beforeEach(function (): void {
        $this->admin = User::factory()->create(['role' => Role::Administrator]);

        $this->book = fn (array $overrides = []) => $this->actingAs($this->admin)
            ->postJson('/api/v1/trips', [
                'origin' => 'Manila',
                'destination' => 'Davao City',
                'cargo' => 'Dry goods',
                'weight_kg' => 500,
                'distance_total_m' => 34_000,
                'scheduled_at' => now()->addHours(3)->toIso8601String(),
                ...$overrides,
            ]);
    });

    it('is priced from the card and keeps the trace of which bracket did it', function (): void {
        $zone = ($this->createCard)()->json('data');

        $trip = Trip::findOrFail(($this->book)()->assertCreated()->json('data.id'));

        expect($trip->price_cents)->toBe(432_000);
        expect($trip->pricing_zone_id)->toBe($zone['id']);
        expect($trip->pricing_bracket_id)->toBe($zone['brackets'][1]['id']);
        expect($trip->fuel_adjustment_bp)->toBe(0);
    });

    it('records the fuel adjustment that was in force when it was quoted', function (): void {
        ($this->createCard)();

        $this->actingAs($this->accountant)
            ->postJson('/api/v1/pricing/diesel', ['price_per_litre_cents' => 7_150])
            ->assertCreated();

        $trip = Trip::findOrFail(($this->book)()->assertCreated()->json('data.id'));

        expect($trip->fuel_adjustment_bp)->toBe(350);
        expect($trip->price_cents)->toBe(447_120);
    });

    it('leaves a negotiated price alone rather than re-deriving it', function (): void {
        ($this->createCard)();

        $trip = Trip::findOrFail(($this->book)(['price_cents' => 0])->assertCreated()->json('data.id'));

        // Zero is how the company books its own freight, and the card must not
        // overrule it on the way in.
        expect($trip->price_cents)->toBe(0);
    });

    it('survives its zone being deleted, keeping the price it was quoted', function (): void {
        $zone = ($this->createCard)()->json('data');

        $trip = Trip::findOrFail(($this->book)()->assertCreated()->json('data.id'));

        $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/pricing/zones/{$zone['id']}")
            ->assertNoContent();

        expect($trip->refresh()->price_cents)->toBe(432_000);
        // Soft-deleted, so the trace still resolves — the invoice can say where
        // its figure came from even after the zone is retired.
        expect(PricingZone::withTrashed()->find($zone['id'])->name)->toBe('Davao City');
    });
});
