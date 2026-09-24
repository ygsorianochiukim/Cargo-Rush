<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Models\TruckCategory;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TruckCategorySeeder;

/**
 * The firm's plain distance card — the half of the rate card with no bands in
 * it.
 *
 * A haulier whose price is simply "450 km is ₱5,000" says that here, in lines
 * that carry their own kilometres and belong to no zone. It is the whole card
 * for a firm with no published table behind it, and the fallback for a firm
 * that has one: a subsidy table stopping at 600 km with a "600 km and beyond"
 * line under it prices a 700 km run off that line rather than off the config
 * tariff.
 *
 * What these tests are defending:
 *
 *   **A card with no bands in it works.** The first describe block is exactly
 *   that: set one line, quote a run, get that line.
 *
 *   **A freezer is not a flatbed.** Reefer work carries a premium that has
 *   nothing to do with distance, and the card can say so.
 *
 *   **Most specific wins.** A banded line, a category line and a plain line can
 *   all cover one run. The office means the most particular. Anything else
 *   makes a rate card something you have to reason about row order to read.
 *
 *   **A band is a refinement, not a wall.** A distance past the end of the
 *   bands reaches the plain card, and a run no line covers at all still falls
 *   back to the configured tariff rather than to zero.
 *
 * The bands themselves, and the published figures in them, are
 * `RateCardTest` and `SubsidyRateCardTest`.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(TruckCategorySeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->freezer = TruckCategory::where('key', 'freezer')->firstOrFail();
    $this->dry = TruckCategory::where('key', 'dry-goods')->firstOrFail();

    /** Save the firm's plain distance card — no places in it. */
    $this->saveCard = fn (array $brackets) => $this->actingAs($this->admin)
        ->putJson('/api/v1/pricing/card', ['brackets' => $brackets]);

    /** What a run would be quoted. */
    $this->quote = fn (array $payload = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/pricing/quote', [
            'distance_m' => 450_000,
            'weight_kg' => 0,
            ...$payload,
        ]);
});

describe('a card with no places in it', function (): void {
    it('prices a 450 km run at the line that covers it', function (): void {
        // The example, literally: 450 km is ₱5,000.
        ($this->saveCard)([
            ['label' => 'Within 100 km', 'min_km' => 0, 'max_km' => 100, 'base_cents' => 150_000],
            ['label' => '100–500 km', 'min_km' => 100, 'max_km' => 500, 'base_cents' => 500_000],
            ['label' => '500 km and beyond', 'min_km' => 500, 'max_km' => null, 'base_cents' => 900_000],
        ])->assertOk();

        $quote = ($this->quote)(['destination' => 'Somewhere nobody has zoned'])
            ->assertOk()->json('data');

        expect($quote['card_cents'])->toBe(500_000)
            ->and($quote['km'])->toBe(450)
            // `card` rather than `zone`: no zone priced this, and the trace
            // column the office reads must not claim one did.
            ->and($quote['source'])->toBe('card')
            ->and($quote['zone'])->toBeNull()
            ->and($quote['bracket']['label'])->toBe('100–500 km');
    });

    it('still charges per km and per kg where the line says to', function (): void {
        ($this->saveCard)([[
            'label' => 'Anywhere', 'min_km' => 0, 'max_km' => null,
            'base_cents' => 100_000, 'per_km_cents' => 1_000, 'per_kg_cents' => 50,
        ]])->assertOk();

        // 1,000 + (450 × 10) + (200 × 0.50) = ₱5,700
        expect(($this->quote)(['weight_kg' => 200])->assertOk()->json('data.card_cents'))
            ->toBe(100_000 + 450 * 1_000 + 200 * 50);
    });

    it('falls back to the configured tariff where no line covers the run', function (): void {
        // A gap the office left: 0–100 and nothing beyond.
        ($this->saveCard)([
            ['label' => 'Within 100 km', 'min_km' => 0, 'max_km' => 100, 'base_cents' => 150_000],
        ])->assertOk();

        expect(($this->quote)()->assertOk()->json('data.source'))->toBe('tariff');
    });
});

describe('pricing by kind of truck', function (): void {
    it('charges a freezer run more than a dry one over the same distance', function (): void {
        ($this->saveCard)([
            ['label' => '100–500 km', 'min_km' => 100, 'max_km' => 500, 'base_cents' => 500_000],
            [
                'label' => '100–500 km, freezer', 'min_km' => 100, 'max_km' => 500,
                'base_cents' => 750_000, 'truck_category_id' => $this->freezer->id,
            ],
        ])->assertOk();

        $dry = ($this->quote)(['truck_category_id' => $this->dry->id])->assertOk()->json('data');
        $freezer = ($this->quote)(['truck_category_id' => $this->freezer->id])->assertOk()->json('data');
        $unstated = ($this->quote)()->assertOk()->json('data');

        expect($freezer['card_cents'])->toBe(750_000)
            // A category the card says nothing about takes the general line.
            ->and($dry['card_cents'])->toBe(500_000)
            // And so does a booking that did not ask for anything particular.
            ->and($unstated['card_cents'])->toBe(500_000);
    });

    it('lets a category line stand alone, with no general line beside it', function (): void {
        ($this->saveCard)([[
            'label' => 'Freezer, anywhere', 'min_km' => 0, 'max_km' => null,
            'base_cents' => 800_000, 'truck_category_id' => $this->freezer->id,
        ]])->assertOk();

        expect(($this->quote)(['truck_category_id' => $this->freezer->id])
            ->assertOk()->json('data.card_cents'))->toBe(800_000)
            // Nothing prices a dry run, so it falls back — which is honest, not
            // a silent zero.
            ->and(($this->quote)(['truck_category_id' => $this->dry->id])
                ->assertOk()->json('data.source'))->toBe('tariff');
    });
});

describe('when several lines could apply', function (): void {
    beforeEach(function (): void {
        /**
         * A band covering everything up to 500 km, with two lines under it —
         * one for the fleet, one for a freezer. The band's kilometres live on
         * the zone; its lines carry money only.
         */
        $this->actingAs($this->admin)->postJson('/api/v1/pricing/zones', [
            'name' => 'Zone Z',
            'code' => 'Z',
            'min_km' => 0,
            'max_km' => 500,
            'brackets' => [
                ['label' => 'Band Z', 'base_cents' => 600_000],
                [
                    'label' => 'Band Z freezer',
                    'base_cents' => 950_000,
                    'truck_category_id' => $this->freezer->id,
                ],
            ],
        ])->assertCreated();

        // The plain card picks up where the band stops, which is the shape a
        // firm working from a published table actually has: the table to its
        // last band, and its own line for anything longer.
        ($this->saveCard)([
            ['label' => 'General 500+', 'min_km' => 500, 'max_km' => null, 'base_cents' => 1_100_000],
            [
                'label' => 'General freezer 500+', 'min_km' => 500, 'max_km' => null,
                'base_cents' => 1_400_000, 'truck_category_id' => $this->freezer->id,
            ],
        ])->assertOk();
    });

    it('takes the most specific line, not the first one', function (): void {
        // A 450 km freezer run is covered by the band's general line and its
        // freezer line. The office means the freezer one.
        $quote = ($this->quote)(['truck_category_id' => $this->freezer->id])
            ->assertOk()->json('data');

        expect($quote['card_cents'])->toBe(950_000)
            ->and($quote['source'])->toBe('zone')
            ->and($quote['bracket']['label'])->toBe('Band Z freezer');
    });

    it('drops a step at a time as the run gets less particular', function (): void {
        $bandDry = ($this->quote)(['truck_category_id' => $this->dry->id]);
        $bandAny = ($this->quote)();
        $beyondFreezer = ($this->quote)(['distance_m' => 700_000, 'truck_category_id' => $this->freezer->id]);
        $beyondAny = ($this->quote)(['distance_m' => 700_000]);

        // Inside the band, the band's lines. Past it, the plain card's — and
        // within each, the category line over the general one.
        expect($bandDry->json('data.card_cents'))->toBe(600_000)
            ->and($bandAny->json('data.card_cents'))->toBe(600_000)
            ->and($beyondFreezer->json('data.card_cents'))->toBe(1_400_000)
            ->and($beyondAny->json('data.card_cents'))->toBe(1_100_000);
    });

    it('lets the plain card price a distance no band covers', function (): void {
        // The band stops at 500 km. A longer run is not thereby unpriced — a
        // band is a refinement, not a wall that hides the rest of the card.
        $quote = ($this->quote)(['distance_m' => 700_000])->assertOk()->json('data');

        expect($quote['card_cents'])->toBe(1_100_000)
            ->and($quote['source'])->toBe('card')
            ->and($quote['zone'])->toBeNull();
    });
});

describe('what the card refuses', function (): void {
    it('refuses a line that ends before it starts', function (): void {
        ($this->saveCard)([
            ['label' => 'Backwards', 'min_km' => 100, 'max_km' => 50, 'base_cents' => 100_000],
        ])->assertStatus(422)
            ->assertJsonFragment(['A line has to end further out than it starts.']);
    });

    it('refuses two lines covering the same distance for the same truck', function (): void {
        ($this->saveCard)([
            ['label' => 'A', 'min_km' => 0, 'max_km' => 100, 'base_cents' => 100_000],
            ['label' => 'B', 'min_km' => 50, 'max_km' => 200, 'base_cents' => 200_000],
        ])->assertStatus(422);
    });

    it('allows the same distance for two different kinds of truck', function (): void {
        // The whole point, and the case a naive overlap check would break.
        ($this->saveCard)([
            ['label' => 'Dry', 'min_km' => 0, 'max_km' => 100, 'base_cents' => 100_000,
                'truck_category_id' => $this->dry->id],
            ['label' => 'Freezer', 'min_km' => 0, 'max_km' => 100, 'base_cents' => 180_000,
                'truck_category_id' => $this->freezer->id],
            ['label' => 'Anything else', 'min_km' => 0, 'max_km' => 100, 'base_cents' => 120_000],
        ])->assertOk();
    });

    it('will not price against another firm’s category', function (): void {
        $rival = $this->makeCompany('Rival Freight');
        $theirs = $this->asCompany($rival, fn () => TruckCategory::create([
            'key' => 'reefer', 'name' => 'Reefer',
        ]));

        ($this->saveCard)([
            ['label' => 'Theirs', 'min_km' => 0, 'max_km' => 100, 'base_cents' => 100_000,
                'truck_category_id' => $theirs->id],
        ])->assertStatus(422);
    });
});

describe('keeping the categories', function (): void {
    it('ships a firm the kinds of unit it is likely to run', function (): void {
        $names = collect($this->actingAs($this->admin)
            ->getJson('/api/v1/pricing/truck-categories')->assertOk()->json('data'))
            ->pluck('name');

        expect($names)->toContain('Dry Goods', 'Freezer / Reefer', 'Flatbed', 'Tanker');
    });

    it('lets the office add one of its own', function (): void {
        $added = $this->actingAs($this->admin)
            ->postJson('/api/v1/pricing/truck-categories', ['name' => 'Side Curtain'])
            ->assertCreated()->json('data');

        expect($added['key'])->toBe('side-curtain')
            ->and($added['name'])->toBe('Side Curtain');
    });

    it('retires one the rate card is using rather than deleting it', function (): void {
        ($this->saveCard)([
            ['label' => 'Freezer', 'min_km' => 0, 'max_km' => null, 'base_cents' => 800_000,
                'truck_category_id' => $this->freezer->id],
        ])->assertOk();

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/v1/pricing/truck-categories/{$this->freezer->id}")
            ->assertOk();

        // Deleting it would have widened that line to "any truck" and silently
        // changed what a dry run is quoted.
        expect($response->json('meta.retired'))->toBeTrue()
            ->and(TruckCategory::query()->find($this->freezer->id))->not->toBeNull();
    });

    it('deletes one nothing depends on', function (): void {
        $spare = $this->actingAs($this->admin)
            ->postJson('/api/v1/pricing/truck-categories', ['name' => 'Side Curtain'])
            ->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/pricing/truck-categories/{$spare['id']}")
            ->assertNoContent();
    });
});
