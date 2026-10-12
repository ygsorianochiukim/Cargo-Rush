<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Shared\Enums\Role;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\SubsidyRateCardSeeder;

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

describe('the diesel columns, cell by cell', function (): void {
    it('reproduces the figure the table prints', function (
        int $diesel,
        int $km,
        int $pesos,
    ): void {
        ($this->diesel)($diesel * 100);

        expect(($this->quote)(['distance_km' => $km])->json('data.cents'))->toBe($pesos * 100);
    })->with([
        [85, 20, 4_753],
        [85, 60, 6_406],
        [85, 180, 14_862],
        [85, 580, 46_026],
        [99, 20, 4_949],
        [99, 580, 48_966],
        [100, 20, 4_963],
        [114, 300, 28_149],
        [114, 580, 52_116],
        [115, 20, 5_173],
        [129, 20, 5_369],
        [129, 460, 44_364],
        [129, 580, 55_266],
    ]);

    it('reproduces a brand new unit’s printed figure too', function (): void {
        ($this->diesel)(8_500);

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

        expect($quote->json('data.source'))->toBe('unzoned');
        expect($quote->json('data.needs_zone'))->toBeTrue();
        expect($quote->json('data.cents'))->toBeNull();
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

        expect(($this->quote)(['distance_km' => 180])->json('data.cents'))->toBe(1_794_200);
        expect(($this->quote)(['distance_km' => 180, 'pricing_zone_id' => $e2->id])->json('data.cents'))
            ->toBe(2_101_900);
    });
});

describe('a 10 wheeler, priced off the 28 cbm line', function (): void {
    it('divides the 28 cbm figure by 28 and times it by 54', function (): void {
        PricingBracket::query()
            ->whereNull('truck_category_id')
            ->where('zone_id', PricingZone::query()->where('code', 'D')->value('id'))
            ->update(['base_cents' => 1_050_000, 'diesel_step_cents' => 0]);

        $quote = ($this->quote)([
            'distance_km' => 140,
            'truck_category_id' => ($this->category)('10-wheeler'),
        ])->assertOk();

        expect($quote->json('data.zone.code'))->toBe('D');
        expect($quote->json('data.bracket.label'))->toBe('Current fleet');
        expect($quote->json('data.cents'))->toBe(2_025_000);
        expect($quote->json('data.card_cents'))->toBe(2_025_000);
        expect($quote->json('data.volume'))->toBe(['cbm' => 54, 'card_cbm' => 28]);
    });

    it('scales the diesel step with it', function (): void {
        ($this->diesel)(9_100);

        $quote = ($this->quote)([
            'distance_km' => 140,
            'truck_category_id' => ($this->category)('10-wheeler'),
        ])->assertOk();

        expect(($this->quote)(['distance_km' => 140])->json('data.cents'))->toBe(1_049_100);
        expect($quote->json('data.cents'))->toBe(2_023_264);
        expect($quote->json('data.card_cents'))->toBe(1_504_864);
        expect($quote->json('data.diesel.step_cents'))->toBe(10_800);
    });

    it('leaves the 28 cbm runs alone', function (): void {
        expect(($this->quote)(['distance_km' => 140])->json('data.volume'))->toBeNull();
        expect(($this->quote)(['distance_km' => 140])->json('data.cents'))->toBe(780_300);
    });

    it('does not scale a class that has a line of its own', function (): void {
        TruckCategory::query()->where('key', 'brand-new-truck')->update(['cbm' => 54]);

        $quote = ($this->quote)([
            'distance_km' => 140,
            'truck_category_id' => ($this->category)('brand-new-truck'),
        ])->assertOk();

        expect($quote->json('data.cents'))->toBe(909_000);
        expect($quote->json('data.volume'))->toBeNull();
    });

    it('keeps the size a class is given', function (): void {
        $made = $this->actingAs($this->accountant)
            ->postJson('/api/v1/pricing/truck-categories', ['name' => '12 Wheeler', 'cbm' => 70])
            ->assertCreated();

        expect($made->json('data.cbm'))->toBe(70);

        $quote = ($this->quote)(['distance_km' => 140, 'truck_category_id' => $made->json('data.id')]);

        expect($quote->json('data.cents'))->toBe(1_950_750);
    });
});

describe('the truck sizes list', function (): void {
    it('resizes a class and reprices it', function (): void {
        $ten = ($this->category)('10-wheeler');

        $this->actingAs($this->accountant)
            ->patchJson("/api/v1/pricing/truck-categories/{$ten}", ['name' => '10 Wheeler', 'cbm' => 56])
            ->assertOk()
            ->assertJsonPath('data.cbm', 56);

        expect(($this->quote)(['distance_km' => 140, 'truck_category_id' => $ten])->json('data.cents'))
            ->toBe(1_560_600);
    });

    it('refuses a size of nothing', function (): void {
        $this->actingAs($this->accountant)
            ->postJson('/api/v1/pricing/truck-categories', ['name' => 'Tiny', 'cbm' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cbm');
    });

    it('removes a size nobody uses', function (): void {
        $ten = ($this->category)('10-wheeler');

        $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/pricing/truck-categories/{$ten}")
            ->assertNoContent();

        expect(TruckCategory::query()->whereKey($ten)->exists())->toBeFalse();
    });
});
