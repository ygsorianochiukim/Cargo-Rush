<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Shared\Enums\Role;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\SubsidyRateCardSeeder;

/**
 * The seeded subsidy card, checked against the document it was typed from.
 *
 * *SUBSIDY TABLE 2024_CDO 28cbm*, CDI-CO-DY-OD-280-0001-8 — seventeen bands,
 * three classes of unit, and the pump-price columns tabulated out to ₱129/L.
 *
 * This exists because the card is a **transcription**, and a transcription's
 * only real failure mode is a figure typed wrong. Nothing about the code can
 * catch that: ₱4,165 and ₱4,615 are equally valid bases, and the second is
 * only detectable by comparing against the sheet. So the figures below were
 * read off the document rather than off the seeder, and the ones that carry a
 * pump price are cells the document prints — if the arithmetic and the
 * transcription are both right, the system answers exactly what the desk would
 * read off the paper.
 *
 * Every assertion here is a number somebody can look up. That is the point.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->accountant = User::factory()->create(['role' => Role::Accountant]);
    $this->seed(SubsidyRateCardSeeder::class);

    $this->quote = fn (array $payload) => $this->actingAs($this->accountant)
        ->postJson('/api/v1/pricing/quote', $payload);

    $this->diesel = fn (int $cents) => $this->actingAs($this->accountant)
        ->postJson('/api/v1/pricing/diesel', ['price_per_litre_cents' => $cents])
        ->assertCreated();

    $this->category = fn (string $key): string => TruckCategory::query()
        ->where('key', $key)->firstOrFail()->id;
});

describe('the card as seeded', function (): void {
    it('lays down the seventeen bands the document publishes', function (): void {
        expect(PricingZone::query()->count())->toBe(17);

        expect(PricingZone::query()->inBandOrder()->pluck('code')->all())->toBe([
            'A1', 'A2', 'B', 'C', 'D', 'E1', 'E2', 'F',
            'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O',
        ]);
    });

    it('stores each band’s kilometres the way the table prints them', function (): void {
        $bands = PricingZone::query()->inBandOrder()->get()
            ->mapWithKeys(fn (PricingZone $zone): array => [$zone->code => $zone->band()]);

        // A1 starts at zero rather than one, so a trip booked with no distance
        // lands on the shortest published rate instead of falling off the card.
        expect($bands['A1'])->toBe('0 – 40 km');
        expect($bands['A2'])->toBe('1 – 40 km');
        expect($bands['B'])->toBe('41 – 80 km');
        expect($bands['E2'])->toBe('161 – 200 km');
        expect($bands['O'])->toBe('561 – 600 km');
    });

    it('gives every band three rate lines — the fleet, brand new, and recon', function (): void {
        $a1 = PricingZone::query()->where('code', 'A1')->firstOrFail();

        expect($a1->brackets)->toHaveCount(3);
        expect($a1->brackets->pluck('label')->all())
            ->toBe(['Current fleet', 'Brand new truck', 'Recon truck']);
        // The fleet rate names no class of unit, because it is what a booking
        // that asks for nothing in particular should be charged.
        expect($a1->brackets->firstWhere('label', 'Current fleet')->truck_category_id)->toBeNull();
    });

    it('draws every band at the ₱43.00/L baseline the document states', function (): void {
        expect(PricingZone::query()->pluck('diesel_baseline_cents')->unique()->all())->toBe([4_300]);
    });

    it('runs twice without doubling the card', function (): void {
        $this->seed(SubsidyRateCardSeeder::class);

        expect(PricingZone::query()->count())->toBe(17);
        expect(PricingBracket::query()->count())->toBe(51);
    });
});

/**
 * The `Current Price` column, band by band, with no pump price recorded.
 *
 * A distance in the middle of each band, so the band is chosen by arithmetic
 * rather than by landing on a boundary — the boundaries get their own test
 * below.
 */
describe('the current-fleet column', function (): void {
    it('prices a run in every band at the published figure', function (
        int $km,
        string $code,
        int $pesos,
    ): void {
        $quote = ($this->quote)(['distance_km' => $km])->assertOk();

        expect($quote->json('data.zone.code'))->toBe($code);
        expect($quote->json('data.cents'))->toBe($pesos * 100);
    })->with([
        //     km,  band,  ₱ published
        [20, 'A1', 4_165],
        [60, 'B', 5_230],
        [100, 'C', 6_530],
        [140, 'D', 7_803],
        [180, 'E1', 11_922],
        [220, 'F', 17_210],
        [260, 'G', 17_766],
        [300, 'H', 20_197],
        [340, 'I', 22_626],
        [380, 'J', 25_056],
        [420, 'K', 27_486],
        [460, 'L', 29_916],
        [500, 'M', 32_346],
        [540, 'N', 34_776],
        [580, 'O', 37_206],
    ]);
});

describe('the brand new and recon columns', function (): void {
    it('charges a brand new unit the brand new figure', function (): void {
        $quote = ($this->quote)([
            'distance_km' => 20,
            'truck_category_id' => ($this->category)('brand-new-truck'),
        ])->assertOk();

        expect($quote->json('data.bracket.label'))->toBe('Brand new truck');
        expect($quote->json('data.cents'))->toBe(491_600);
    });

    it('charges a recon unit the recon figure', function (): void {
        $quote = ($this->quote)([
            'distance_km' => 580,
            'truck_category_id' => ($this->category)('recon-truck'),
        ])->assertOk();

        expect($quote->json('data.cents'))->toBe(4_029_200);
    });

    it('charges the fleet figure when the booking asks for nothing particular', function (): void {
        expect(($this->quote)(['distance_km' => 580])->json('data.cents'))->toBe(3_720_600);
    });
});

/**
 * The tabulated columns — the part of the document the desk actually reads a
 * figure off.
 *
 * Each of these is a printed cell. `A1 at ₱85` is the top-left of the first
 * diesel block; `O at ₱129` is the bottom-right of the last.
 */
describe('the diesel columns, cell by cell', function (): void {
    it('reproduces the figure the table prints', function (
        int $diesel,
        int $km,
        int $pesos,
    ): void {
        ($this->diesel)($diesel * 100);

        expect(($this->quote)(['distance_km' => $km])->json('data.cents'))->toBe($pesos * 100);
    })->with([
        // ₱/L, km,  ₱ printed in that column
        //
        // The first block, ₱85 to ₱99.
        [85, 20, 4_753],    // A1
        [85, 60, 6_406],    // B
        [85, 180, 14_862],  // E1
        [85, 580, 46_026],  // O
        [99, 20, 4_949],    // A1, last column of the first block
        [99, 580, 48_966],  // O
        //
        // The second block, ₱100 to ₱114.
        [100, 20, 4_963],
        [114, 300, 28_149],  // H
        [114, 580, 52_116],  // O
        //
        // The third block, ₱115 to ₱129.
        [115, 20, 5_173],
        [129, 20, 5_369],   // A1, the last column on the sheet
        [129, 460, 44_364], // L
        [129, 580, 55_266], // O, the bottom-right cell
    ]);

    it('reproduces a brand new unit’s printed figure too', function (): void {
        ($this->diesel)(8_500);

        // Sheet 2, band A1, the ₱85 column: 4,916 + 14 × 42.
        $quote = ($this->quote)([
            'distance_km' => 20,
            'truck_category_id' => ($this->category)('brand-new-truck'),
        ])->assertOk();

        expect($quote->json('data.cents'))->toBe(550_400);
    });

    it('shows the working, so a figure can be checked against the sheet', function (): void {
        ($this->diesel)(8_500);

        $diesel = ($this->quote)(['distance_km' => 20])->json('data.diesel');

        expect($diesel['baseline_cents'])->toBe(4_300);
        expect($diesel['price_per_litre_cents'])->toBe(8_500);
        expect($diesel['step_cents'])->toBe(1_400);
        expect($diesel['pesos_above_baseline'])->toBe(42);
    });
});

describe('where the bands meet', function (): void {
    it('puts a run on exactly one band at every boundary', function (
        int $km,
        string $code,
    ): void {
        expect(($this->quote)(['distance_km' => $km])->json('data.zone.code'))->toBe($code);
    })->with([
        // The table's "1 to 40" then "41 to 80": 40 is the last kilometre of
        // A1 and 41 the first of B. Closed ranges on both ends would match 40
        // twice or 41 not at all, and the two bugs look identical from outside.
        [40, 'A1'],
        [41, 'B'],
        [80, 'B'],
        [81, 'C'],
        [200, 'E1'],
        [201, 'F'],
        [600, 'O'],
    ]);

    it('runs off the end of the card past 600 km, and says so', function (): void {
        $quote = ($this->quote)(['distance_km' => 601])->assertOk();

        // The document stops at 600. A figure invented beyond it would be the
        // system's, not the principal's, and the trace has to admit which.
        expect($quote->json('data.source'))->toBe('tariff');
        expect($quote->json('data.zone'))->toBeNull();
    });
});

describe('the bands that share a distance', function (): void {
    it('defaults to the lower of A1 and A2, and offers the other', function (): void {
        $quote = ($this->quote)(['distance_km' => 30])->assertOk();

        expect($quote->json('data.zone.code'))->toBe('A1');
        expect($quote->json('data.cents'))->toBe(416_500);
        expect(collect($quote->json('data.zone_alternatives'))->pluck('code')->all())->toBe(['A2']);
    });

    it('prices A2 when the desk picks it', function (): void {
        $a2 = PricingZone::query()->where('code', 'A2')->firstOrFail();

        $quote = ($this->quote)(['distance_km' => 30, 'pricing_zone_id' => $a2->id])->assertOk();

        expect($quote->json('data.cents'))->toBe(441_200);
    });

    it('does the same for E1 and E2', function (): void {
        $e2 = PricingZone::query()->where('code', 'E2')->firstOrFail();

        expect(($this->quote)(['distance_km' => 180])->json('data.zone.code'))->toBe('E1');
        expect(($this->quote)(['distance_km' => 180])->json('data.cents'))->toBe(1_192_200);
        expect(($this->quote)(['distance_km' => 180, 'pricing_zone_id' => $e2->id])->json('data.cents'))
            ->toBe(1_456_900);
    });

    it('keeps their steps apart, which is where the two diverge fastest', function (): void {
        ($this->diesel)(12_900);
        $e2 = PricingZone::query()->where('code', 'E2')->firstOrFail();

        // The ₱129 column of the last block: E1 is 11,922 + 70 × 86 = 17,942,
        // E2 is 14,569 + 75 × 86 = 21,019. Sharing one step would put both
        // wrong, and only one of them visibly.
        expect(($this->quote)(['distance_km' => 180])->json('data.cents'))->toBe(1_794_200);
        expect(($this->quote)(['distance_km' => 180, 'pricing_zone_id' => $e2->id])->json('data.cents'))
            ->toBe(2_101_900);
    });
});
