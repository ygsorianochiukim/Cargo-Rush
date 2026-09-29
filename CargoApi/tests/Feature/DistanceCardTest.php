<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Pricing\Models\TruckCategory;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TruckCategorySeeder;

/**
 * The old plain distance card, and the rule that replaced it.
 *
 * A haulier used to be able to say "450 km is ₱5,000" in lines that carried
 * their own kilometres and belonged to no zone, and those lines priced any run
 * the zones did not cover. They no longer price anything: pricing is zone-only,
 * and a run the zones miss is saved unpriced for the office (`PricingService`).
 *
 * What these tests are defending now:
 *
 *   **No new zoneless line.** The card endpoint refuses a line without an id,
 *   so nobody can rebuild the fallback by hand. The rows an install already
 *   has can still be corrected or removed — they are not deleted behind the
 *   office's back.
 *
 *   **Those rows never price a trip.** Not inside a zone's band, not past it.
 *
 *   **A freezer is not a flatbed, inside a zone.** The class of truck picks
 *   the zone's line, most specific first; a class the zone has no line for is
 *   unpriced, with the reason naming the class.
 *
 * The bands and their published figures are `RateCardTest` and
 * `SubsidyRateCardTest`; the unpriced trip itself is `ZoneOnlyPricingTest`.
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

    /** Save the old zoneless card, whole. */
    $this->saveCard = fn (array $brackets) => $this->actingAs($this->admin)
        ->putJson('/api/v1/pricing/card', ['brackets' => $brackets]);

    /** A zoneless line as an older install would hold it. */
    $this->legacyLine = fn (array $attributes = []) => PricingBracket::create([
        'zone_id' => null,
        'label' => '100–500 km',
        'min_km' => 100,
        'max_km' => 500,
        'base_cents' => 500_000,
        'position' => 0,
        ...$attributes,
    ]);

    /** One band, 0–500 km, with the lines given. */
    $this->zone = fn (array $brackets) => $this->actingAs($this->admin)->postJson('/api/v1/pricing/zones', [
        'name' => 'Zone Z',
        'code' => 'Z',
        'min_km' => 0,
        'max_km' => 500,
        'brackets' => $brackets,
    ])->assertCreated();

    /** What a run would be quoted. */
    $this->quote = fn (array $payload = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/pricing/quote', [
            'distance_m' => 450_000,
            'weight_kg' => 0,
            ...$payload,
        ]);
});

describe('the card takes no new lines', function (): void {
    it('refuses a zoneless line and points at the zones instead', function (): void {
        ($this->saveCard)([
            ['label' => '100–500 km', 'min_km' => 100, 'max_km' => 500, 'base_cents' => 500_000],
        ])->assertStatus(422)
            ->assertJsonFragment(['The distance card no longer prices trips. Add this line to a zone on the Pricing card instead.']);

        expect(PricingBracket::query()->whereNull('zone_id')->count())->toBe(0);
    });

    it('still lets the office correct or remove the lines it already has', function (): void {
        $line = ($this->legacyLine)();

        ($this->saveCard)([
            ['id' => $line->id, 'label' => 'Renamed', 'min_km' => 100, 'max_km' => 500, 'base_cents' => 450_000],
        ])->assertOk()->assertJsonPath('data.0.label', 'Renamed');

        ($this->saveCard)([])->assertOk();

        expect(PricingBracket::query()->whereNull('zone_id')->count())->toBe(0);
    });

    it('still refuses an edit that would make an existing line nonsense', function (): void {
        $line = ($this->legacyLine)();

        ($this->saveCard)([
            ['id' => $line->id, 'label' => 'Backwards', 'min_km' => 100, 'max_km' => 50, 'base_cents' => 100_000],
        ])->assertStatus(422)
            ->assertJsonFragment(['A line has to end further out than it starts.']);
    });

    it('will not point an existing line at another firm’s category', function (): void {
        $line = ($this->legacyLine)();
        $rival = $this->makeCompany('Rival Freight');
        $theirs = $this->asCompany($rival, fn () => TruckCategory::create([
            'key' => 'reefer', 'name' => 'Reefer',
        ]));

        ($this->saveCard)([
            ['id' => $line->id, 'label' => 'Theirs', 'min_km' => 0, 'max_km' => 100, 'base_cents' => 100_000,
                'truck_category_id' => $theirs->id],
        ])->assertStatus(422);
    });
});

describe('an old zoneless line never prices a run', function (): void {
    it('leaves a run it covers unpriced when no zone covers it', function (): void {
        ($this->legacyLine)();

        $quote = ($this->quote)()->assertOk()->json('data');

        expect($quote['cents'])->toBeNull()
            ->and($quote['needs_zone'])->toBeTrue()
            ->and($quote['source'])->toBe('unzoned')
            ->and($quote['bracket'])->toBeNull();
    });

    it('does not pick up a run past the end of the zones either', function (): void {
        ($this->zone)([['label' => 'Band Z', 'base_cents' => 600_000]]);
        ($this->legacyLine)([
            'label' => '500 km and beyond', 'min_km' => 500, 'max_km' => null, 'base_cents' => 1_100_000,
        ]);

        // Inside the band, the band's line. Past it, nothing — not the
        // zoneless "500 km and beyond" line that used to catch it.
        expect(($this->quote)()->json('data.cents'))->toBe(600_000);

        ($this->quote)(['distance_m' => 700_000])->assertOk()
            ->assertJsonPath('data.cents', null)
            ->assertJsonPath('data.needs_zone', true)
            ->assertJsonPath('data.reason', 'No zone covers 700 km.');
    });
});

describe('pricing by kind of truck, inside a zone', function (): void {
    beforeEach(function (): void {
        ($this->zone)([
            ['label' => 'Band Z', 'base_cents' => 600_000],
            ['label' => 'Band Z freezer', 'base_cents' => 950_000, 'truck_category_id' => $this->freezer->id],
        ]);
    });

    it('takes the most specific line, not the first one', function (): void {
        $quote = ($this->quote)(['truck_category_id' => $this->freezer->id])->assertOk()->json('data');

        expect($quote['card_cents'])->toBe(950_000)
            ->and($quote['source'])->toBe('zone')
            ->and($quote['bracket']['label'])->toBe('Band Z freezer');
    });

    it('gives a class the zone says nothing about its general line', function (): void {
        expect(($this->quote)(['truck_category_id' => $this->dry->id])->json('data.card_cents'))->toBe(600_000)
            ->and(($this->quote)()->json('data.card_cents'))->toBe(600_000);
    });

    it('leaves a class unpriced where the zone has only another class’s line', function (): void {
        $this->actingAs($this->admin)->postJson('/api/v1/pricing/zones', [
            'name' => 'Zone Y', 'code' => 'Y', 'min_km' => 500, 'max_km' => 1_000,
            'brackets' => [
                ['label' => 'Freezer only', 'base_cents' => 800_000, 'truck_category_id' => $this->freezer->id],
            ],
        ])->assertCreated();

        expect(($this->quote)(['distance_m' => 700_000, 'truck_category_id' => $this->freezer->id])
            ->json('data.cents'))->toBe(800_000);

        ($this->quote)(['distance_m' => 700_000, 'truck_category_id' => $this->dry->id])->assertOk()
            ->assertJsonPath('data.needs_zone', true)
            ->assertJsonPath('data.reason', 'Zone Y (500 – 999 km) has no line for a Dry Goods.');
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
        ($this->zone)([
            ['label' => 'Freezer', 'base_cents' => 800_000, 'truck_category_id' => $this->freezer->id],
        ]);

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
