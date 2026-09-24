<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Shared\Enums\Role;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\NavigationSeeder;

/**
 * The rate card: a band of kilometres, its rates, and what diesel does to them.
 *
 * A zone is a band — `A1` is 1–40 km — the way the trade's own subsidy tables
 * write one. What is worth pinning here is not chiefly the arithmetic; it is
 * the edges, because each of them used to produce a zero and a zero reaches
 * the ledger as revenue the business never earned:
 *
 *   a distance past the end of the table,
 *   a band with no rate line on it,
 *   two bands over the same kilometres,
 *   a band the distance has moved out of since it was chosen,
 *   an install that has never recorded a pump price.
 *
 * The published figures themselves are pinned in `SubsidyRateCardTest`, which
 * checks the seeded card against the document it was typed from.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);

    $this->accountant = User::factory()->create(['role' => Role::Accountant]);

    /**
     * Band A1 of the workbook: 1–40 km at ₱4,165, plus ₱14 for every ₱1/L
     * diesel sits above ₱43.
     *
     * Starting at zero rather than one, as the seeder does, so a trip nobody
     * has pinned still lands on a published rate.
     */
    $this->band = [
        'name' => 'Zone A1',
        'code' => 'A1',
        'min_km' => 0,
        'max_km' => 41,
        'diesel_baseline_cents' => 4_300,
        'brackets' => [
            [
                'label' => 'Current fleet',
                'base_cents' => 416_500,
                'diesel_step_cents' => 1_400,
            ],
        ],
    ];

    $this->createBand = fn (array $overrides = []) => $this->actingAs($this->accountant)
        ->postJson('/api/v1/pricing/zones', [...$this->band, ...$overrides]);

    $this->quote = fn (array $payload = []) => $this->actingAs($this->accountant)
        ->postJson('/api/v1/pricing/quote', $payload);

    $this->diesel = fn (int $cents, array $extra = []) => $this->actingAs($this->accountant)
        ->postJson('/api/v1/pricing/diesel', ['price_per_litre_cents' => $cents, ...$extra]);
});

describe('the band editor', function (): void {
    it('saves a band and its rate lines in one request', function (): void {
        $response = ($this->createBand)()->assertCreated();

        expect($response->json('data.code'))->toBe('A1');
        // The upper bound is stored exclusive and shown inclusive, so the band
        // reads the way the printed table does rather than a kilometre out.
        expect($response->json('data.band'))->toBe('0 – 40 km');
        expect($response->json('data.brackets'))->toHaveCount(1);
        expect($response->json('data.brackets.0.diesel_step_cents'))->toBe(1_400);
    });

    it('upper-cases the code, because it is a cell of a printed table', function (): void {
        expect(($this->createBand)(['code' => 'e2'])->assertCreated()->json('data.code'))->toBe('E2');
    });

    it('gives a rate line the band’s kilometres rather than its own', function (): void {
        $line = ($this->createBand)()->assertCreated()->json('data.brackets.0');

        // Null, deliberately: the band is written down once. A line carrying a
        // copy is a second place for 1–40 km to be wrong.
        expect($line['min_km'])->toBeNull();
        expect($line['max_km'])->toBeNull();
        expect($line['range'])->toBe('0 – 40 km');
    });

    it('keeps line ids across an edit, so a priced trip keeps its trace', function (): void {
        $zone = ($this->createBand)()->json('data');
        $firstId = $zone['brackets'][0]['id'];

        $response = $this->actingAs($this->accountant)
            ->patchJson("/api/v1/pricing/zones/{$zone['id']}", [
                'brackets' => [[...$zone['brackets'][0], 'base_cents' => 441_200]],
            ])
            ->assertOk();

        expect($response->json('data.brackets.0.id'))->toBe($firstId);
        expect($response->json('data.brackets.0.base_cents'))->toBe(441_200);
    });

    it('leaves the rates alone when an edit does not mention them', function (): void {
        $zone = ($this->createBand)()->json('data');

        $response = $this->actingAs($this->accountant)
            ->patchJson("/api/v1/pricing/zones/{$zone['id']}", ['name' => 'Zone A1 (dry)'])
            ->assertOk();

        expect($response->json('data.name'))->toBe('Zone A1 (dry)');
        expect($response->json('data.brackets'))->toHaveCount(1);
    });

    it('drops only the lines the card no longer mentions', function (): void {
        $zone = ($this->createBand)([
            'brackets' => [
                ['label' => 'Current fleet', 'base_cents' => 416_500],
                ['label' => 'Spare', 'base_cents' => 500_000, 'truck_category_id' => null],
            ],
        ]);

        // Two lines with no class of truck between them is the ambiguity the
        // editor refuses, so the card is built one line at a time instead.
        $zone->assertStatus(422)->assertJsonValidationErrors('brackets.1.base_cents');
    });

    it('refuses a band that ends before it starts', function (): void {
        ($this->createBand)(['min_km' => 40, 'max_km' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_km');
    });

    it('refuses two rate lines for the same class of truck', function (): void {
        ($this->createBand)([
            'brackets' => [
                ['label' => 'Current fleet', 'base_cents' => 416_500],
                ['label' => 'Current fleet again', 'base_cents' => 441_200],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('brackets.1.base_cents');
    });

    it('accepts two bands over the same kilometres, because that is the table', function (): void {
        ($this->createBand)()->assertCreated();

        // A1 and A2 are both 1–40 km at different money. Nothing about the
        // distance separates them, and refusing the second would make half of
        // every subsidy table unrepresentable.
        ($this->createBand)([
            'name' => 'Zone A2',
            'code' => 'A2',
            'brackets' => [['label' => 'Current fleet', 'base_cents' => 441_200]],
        ])->assertCreated();
    });
});

describe('quoting from a band', function (): void {
    it('picks the band from the distance, with no destination anywhere near it', function (): void {
        ($this->createBand)();

        $quote = ($this->quote)(['distance_km' => 34, 'weight_kg' => 500])->assertOk();

        expect($quote->json('data.source'))->toBe('zone');
        expect($quote->json('data.zone.code'))->toBe('A1');
        expect($quote->json('data.bracket.label'))->toBe('Current fleet');
        // The band is a flat rate. Weight and the exact kilometre inside the
        // band change nothing, which is the whole point of banding it.
        expect($quote->json('data.card_cents'))->toBe(416_500);
    });

    it('charges part-kilometres as whole ones when choosing the band', function (): void {
        ($this->createBand)(['min_km' => 0, 'max_km' => 2]);

        // 1.2 km bills as 2, and 2 is outside a band that stops at 2.
        expect(($this->quote)(['distance_m' => 1_200])->json('data.km'))->toBe(2);
        expect(($this->quote)(['distance_m' => 1_200])->json('data.source'))->toBe('tariff');
        expect(($this->quote)(['distance_m' => 900])->json('data.source'))->toBe('zone');
    });

    it('falls back to the configured tariff past the end of the table', function (): void {
        ($this->createBand)();

        $quote = ($this->quote)(['distance_km' => 700])->assertOk();

        // Visibly off-card rather than a guess: the principal has published no
        // rate for 700 km, and the trace says which figure answered instead.
        expect($quote->json('data.source'))->toBe('tariff');
        expect($quote->json('data.zone'))->toBeNull();
        // The pre-existing formula, untouched: 150,000 + 700 * 3,500.
        expect($quote->json('data.cents'))->toBe(2_600_000);
    });

    it('names the band when the band is the thing without a rate on it', function (): void {
        ($this->createBand)(['brackets' => []]);

        $quote = ($this->quote)(['distance_km' => 20])->assertOk();

        expect($quote->json('data.source'))->toBe('tariff');
        // Named even though it did not price the run, so the office can see the
        // band was found and the *line* was missing rather than hunt for a
        // banding problem that is not there.
        expect($quote->json('data.zone.code'))->toBe('A1');
    });

    it('ignores a band that has been switched off', function (): void {
        $zone = ($this->createBand)()->json('data');

        $this->actingAs($this->accountant)
            ->patchJson("/api/v1/pricing/zones/{$zone['id']}", ['status' => 'inactive'])
            ->assertOk();

        expect(($this->quote)(['distance_km' => 20])->json('data.source'))->toBe('tariff');
    });
});

describe('two bands over one distance', function (): void {
    beforeEach(function (): void {
        $this->a1 = ($this->createBand)()->json('data');
        $this->a2 = ($this->createBand)([
            'name' => 'Zone A2',
            'code' => 'A2',
            'position' => 1,
            'brackets' => [
                ['label' => 'Current fleet', 'base_cents' => 441_200, 'diesel_step_cents' => 1_900],
            ],
        ])->json('data');
    });

    it('takes the table’s own order when nobody has chosen', function (): void {
        $quote = ($this->quote)(['distance_km' => 30])->assertOk();

        // A1 over A2 — the order the table prints them, and the lower of the
        // two figures. A figure somebody argues up is the error the office
        // catches; an overcharge from a system that guessed is the one nobody
        // reports.
        expect($quote->json('data.zone.code'))->toBe('A1');
        expect($quote->json('data.card_cents'))->toBe(416_500);
    });

    it('offers the other band so a client can put the choice on screen', function (): void {
        $quote = ($this->quote)(['distance_km' => 30])->assertOk();

        expect($quote->json('data.zone_alternatives'))->toHaveCount(1);
        expect($quote->json('data.zone_alternatives.0.code'))->toBe('A2');
        // The band that priced it is not repeated in the list of what else
        // could have.
        expect(collect($quote->json('data.zone_alternatives'))->pluck('code'))->not->toContain('A1');
    });

    it('prices the band the desk actually chose', function (): void {
        $quote = ($this->quote)([
            'distance_km' => 30,
            'pricing_zone_id' => $this->a2['id'],
        ])->assertOk();

        expect($quote->json('data.zone.code'))->toBe('A2');
        expect($quote->json('data.card_cents'))->toBe(441_200);
    });
});

describe('diesel, the way the table states it', function (): void {
    beforeEach(function (): void {
        ($this->createBand)();
    });

    it('adds nothing until a pump price is recorded', function (): void {
        $quote = ($this->quote)(['distance_km' => 20])->assertOk();

        expect($quote->json('data.fuel_rule'))->toBe('none');
        expect($quote->json('data.cents'))->toBe($quote->json('data.card_cents'));
    });

    it('adds the band’s step for every whole peso above the baseline', function (): void {
        ($this->diesel)(8_500)->assertCreated();

        $quote = ($this->quote)(['distance_km' => 20])->assertOk();

        // The published figure. ₱85 is 42 pesos above the ₱43 baseline, band A1
        // adds ₱14 a peso, and the table's own ₱85 column says ₱4,753.
        expect($quote->json('data.diesel.pesos_above_baseline'))->toBe(42);
        expect($quote->json('data.fuel_surcharge_cents'))->toBe(58_800);
        expect($quote->json('data.cents'))->toBe(475_300);
        expect($quote->json('data.fuel_rule'))->toBe('step');
    });

    it('stays on the peso the table prints rather than between two columns', function (): void {
        // ₱85.60 sits on the ₱85 column. Rounding up would charge for a peso
        // of diesel the document never published a figure for.
        ($this->diesel)(8_560)->assertCreated();

        expect(($this->quote)(['distance_km' => 20])->json('data.cents'))->toBe(475_300);
    });

    it('adds nothing for diesel inside the baseline band', function (): void {
        // The card holds from ₱30 to ₱43 a litre. ₱38 is what the printed
        // price already assumes, not a discount the table forgot to offer.
        ($this->diesel)(3_800)->assertCreated();

        $quote = ($this->quote)(['distance_km' => 20])->assertOk();

        expect($quote->json('data.fuel_surcharge_cents'))->toBe(0);
        expect($quote->json('data.cents'))->toBe(416_500);
    });

    it('does not quote from a price that has not taken effect yet', function (): void {
        ($this->diesel)(9_000, ['effective_on' => now()->addWeek()->toDateString()])->assertCreated();

        expect(($this->quote)(['distance_km' => 20])->json('data.fuel_surcharge_cents'))->toBe(0);
    });

    it('corrects the day rather than storing two prices for it', function (): void {
        $today = now()->toDateString();

        foreach ([7_000, 6_800] as $price) {
            ($this->diesel)($price, ['effective_on' => $today])->assertCreated();
        }

        $history = $this->actingAs($this->accountant)
            ->getJson('/api/v1/pricing/diesel')->json('data.history');

        expect($history)->toHaveCount(1);
        expect($history[0]['price_per_litre_cents'])->toBe(6_800);
    });
});

describe('the percentage fuel model, for a card with no table behind it', function (): void {
    it('still scales a line that declares no step', function (): void {
        // No `diesel_step_cents`, and a ₱65.00 baseline — a firm that has one
        // card and prices fuel as a share of the fare. Nothing about banding
        // the card took that away.
        ($this->createBand)([
            'diesel_baseline_cents' => 6_500,
            'brackets' => [['label' => 'Current fleet', 'base_cents' => 185_000]],
        ]);

        // ₱71.50 against ₱65.00 is a 10% move, passed through at the 0.35 fuel
        // share: +350 bp.
        ($this->diesel)(7_150)->assertCreated();

        $quote = ($this->quote)(['distance_km' => 20])->assertOk();

        expect($quote->json('data.fuel_rule'))->toBe('percentage');
        expect($quote->json('data.fuel_adjustment_bp'))->toBe(350);
        expect($quote->json('data.cents'))->toBe(191_475);
        expect($quote->json('data.fuel_adjustment_cents'))->toBe(6_475);
    });

    it('discounts when the pump falls below the baseline', function (): void {
        ($this->createBand)([
            'diesel_baseline_cents' => 6_500,
            'brackets' => [['label' => 'Current fleet', 'base_cents' => 185_000]],
        ]);

        ($this->diesel)(5_850)->assertCreated();

        expect(($this->quote)(['distance_km' => 20])->json('data.fuel_adjustment_bp'))->toBe(-350);
    });

    it('caps the swing however far the pump moves', function (): void {
        ($this->createBand)([
            'diesel_baseline_cents' => 6_500,
            'brackets' => [['label' => 'Current fleet', 'base_cents' => 185_000]],
        ]);

        // Triple the baseline. Uncapped that is +7,000 bp; the guard rail is
        // 2,500, and a quote nobody can explain is worse than a stale card.
        ($this->diesel)(19_500)->assertCreated();

        expect(($this->quote)(['distance_km' => 20])->json('data.fuel_adjustment_bp'))->toBe(2_500);

        expect($this->actingAs($this->accountant)->getJson('/api/v1/pricing/diesel')->json('data.capped'))
            ->toBeTrue();
    });
});

describe('a booked trip', function (): void {
    beforeEach(function (): void {
        $this->admin = User::factory()->create(['role' => Role::Administrator]);

        $this->book = fn (array $overrides = []) => $this->actingAs($this->admin)
            ->postJson('/api/v1/trips', [
                'origin' => 'Cagayan de Oro',
                'destination' => 'Iligan',
                'cargo' => 'Dry goods',
                'weight_kg' => 500,
                'distance_total_m' => 34_000,
                'scheduled_at' => now()->addHours(3)->toIso8601String(),
                ...$overrides,
            ]);
    });

    it('is priced from the band and keeps the trace of which line did it', function (): void {
        $zone = ($this->createBand)()->json('data');

        $trip = Trip::findOrFail(($this->book)()->assertCreated()->json('data.id'));

        expect($trip->price_cents)->toBe(416_500);
        expect($trip->pricing_zone_id)->toBe($zone['id']);
        expect($trip->pricing_bracket_id)->toBe($zone['brackets'][0]['id']);
        expect($trip->fuel_surcharge_cents)->toBe(0);
    });

    it('records the diesel surcharge that was in force when it was quoted', function (): void {
        ($this->createBand)();
        ($this->diesel)(8_500)->assertCreated();

        $trip = Trip::findOrFail(($this->book)()->assertCreated()->json('data.id'));

        expect($trip->fuel_surcharge_cents)->toBe(58_800);
        expect($trip->price_cents)->toBe(475_300);
    });

    it('honours the band the desk chose on the booking', function (): void {
        ($this->createBand)();
        $a2 = ($this->createBand)([
            'name' => 'Zone A2',
            'code' => 'A2',
            'position' => 1,
            'brackets' => [['label' => 'Current fleet', 'base_cents' => 441_200]],
        ])->json('data');

        $trip = Trip::findOrFail(
            ($this->book)(['pricing_zone_id' => $a2['id']])->assertCreated()->json('data.id'),
        );

        expect($trip->pricing_zone_id)->toBe($a2['id']);
        expect($trip->price_cents)->toBe(441_200);
    });

    it('re-bands a trip whose distance has moved out of the band it was in', function (): void {
        ($this->createBand)();
        $b = ($this->createBand)([
            'name' => 'Zone B',
            'code' => 'B',
            'min_km' => 41,
            'max_km' => 81,
            'position' => 2,
            'brackets' => [['label' => 'Current fleet', 'base_cents' => 523_000]],
        ])->json('data');

        // Booked with no distance at all — the phone-booking case — so it is
        // quoted in the lowest band and the trace records A1.
        $id = ($this->book)(['distance_total_m' => 0])->assertCreated()->json('data.id');
        expect(Trip::findOrFail($id)->price_cents)->toBe(416_500);

        // A dispatcher then pins the route at 60 km. Honouring the recorded
        // band would charge a 60 km haul at the 0–40 km rate for ever, which
        // is the cost of the trace and the choice sharing one column.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/trips/{$id}", ['distance_total_m' => 60_000])
            ->assertOk();

        $trip = Trip::findOrFail($id);
        expect($trip->pricing_zone_id)->toBe($b['id']);
        expect($trip->price_cents)->toBe(523_000);
    });

    it('leaves a negotiated price alone rather than re-deriving it', function (): void {
        ($this->createBand)();

        $trip = Trip::findOrFail(($this->book)(['price_cents' => 0])->assertCreated()->json('data.id'));

        // Zero is how the company books its own freight, and the card must not
        // overrule it on the way in.
        expect($trip->price_cents)->toBe(0);
    });

    it('survives its band being deleted, keeping the price it was quoted', function (): void {
        $zone = ($this->createBand)()->json('data');

        $trip = Trip::findOrFail(($this->book)()->assertCreated()->json('data.id'));

        $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/pricing/zones/{$zone['id']}")
            ->assertNoContent();

        expect($trip->refresh()->price_cents)->toBe(416_500);
        // Soft-deleted, so the trace still resolves — the invoice can say where
        // its figure came from even after the table is redrawn.
        expect(PricingZone::withTrashed()->find($zone['id'])->code)->toBe('A1');
    });
});

/**
 * Nothing on this screen should be enterable twice.
 *
 * Three ways a band can be duplicated, and they fail differently: a retyped
 * code, the same band under a second code, and a class of truck priced twice
 * inside one band. The editor catches all three before the request — see
 * `PricingPage` — and these pin the half that actually guarantees it.
 */
describe('refusing a duplicate', function (): void {
    it('refuses a second band with the same code', function (): void {
        ($this->createBand)()->assertCreated();

        ($this->createBand)(['name' => 'Something else'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    });

    it('refuses a second band with the same name', function (): void {
        ($this->createBand)()->assertCreated();

        // The commoner mistake: the same band typed again under a new code,
        // which the code rule alone would let straight through.
        ($this->createBand)(['code' => 'A9'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    });

    it('lets a band keep its own code and name when edited', function (): void {
        $zone = ($this->createBand)()->json('data');

        $this->actingAs($this->accountant)
            ->patchJson("/api/v1/pricing/zones/{$zone['id']}", [
                'name' => $zone['name'],
                'code' => $zone['code'],
                'min_km' => 0,
                'max_km' => 61,
            ])
            ->assertOk();
    });

    it('keeps a retired band’s code taken, and says so rather than breaking', function (): void {
        $zone = ($this->createBand)()->json('data');

        $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/pricing/zones/{$zone['id']}")
            ->assertNoContent();

        /**
         * The unique index is on `(company_id, code)` and knows nothing about
         * `deleted_at`, so a rule that excused retired rows would pass
         * validation and then hit the database — a 500 with a constraint name
         * in it, where the office typed a duplicate and deserved to be told.
         *
         * Keeping the code taken is also right on its own terms: a band is
         * retired rather than removed so the trips it priced can still name
         * it, and a second `A1` would leave an old invoice unable to say which
         * of the two quoted it.
         */
        ($this->createBand)()
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    });

    it('refuses two rate lines for the same class of truck', function (): void {
        $category = TruckCategory::query()->first();

        ($this->createBand)([
            'brackets' => [
                ['label' => 'Brand new', 'base_cents' => 416_500, 'truck_category_id' => $category?->id],
                ['label' => 'Brand new again', 'base_cents' => 441_200, 'truck_category_id' => $category?->id],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('brackets.1.base_cents');
    });
});
