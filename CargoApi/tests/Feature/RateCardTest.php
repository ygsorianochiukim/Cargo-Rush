<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Shared\Enums\Role;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\NavigationSeeder;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);

    $this->accountant = User::factory()->create(['role' => Role::Accountant]);

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
        expect($response->json('data.band'))->toBe('0 – 40 km');
        expect($response->json('data.brackets'))->toHaveCount(1);
        expect($response->json('data.brackets.0.diesel_step_cents'))->toBe(1_400);
    });

    it('upper-cases the code, because it is a cell of a printed table', function (): void {
        expect(($this->createBand)(['code' => 'e2'])->assertCreated()->json('data.code'))->toBe('E2');
    });

    it('gives a rate line the band’s kilometres rather than its own', function (): void {
        $line = ($this->createBand)()->assertCreated()->json('data.brackets.0');

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
        expect($quote->json('data.card_cents'))->toBe(416_500);
    });

    it('charges part-kilometres as whole ones when choosing the band', function (): void {
        ($this->createBand)(['min_km' => 0, 'max_km' => 2]);

        expect(($this->quote)(['distance_m' => 1_200])->json('data.km'))->toBe(2);
        expect(($this->quote)(['distance_m' => 1_200])->json('data.source'))->toBe('unzoned');
        expect(($this->quote)(['distance_m' => 900])->json('data.source'))->toBe('zone');
    });

    it('leaves a run past the end of the table unpriced, and says why', function (): void {
        ($this->createBand)();

        $quote = ($this->quote)(['distance_km' => 700])->assertOk();

        expect($quote->json('data.source'))->toBe('unzoned');
        expect($quote->json('data.needs_zone'))->toBeTrue();
        expect($quote->json('data.zone'))->toBeNull();
        expect($quote->json('data.cents'))->toBeNull();
        expect($quote->json('data.reason'))->toBe('No zone covers 700 km.');
    });

    it('names the band when the band is the thing without a rate on it', function (): void {
        ($this->createBand)(['brackets' => []]);

        $quote = ($this->quote)(['distance_km' => 20])->assertOk();

        expect($quote->json('data.source'))->toBe('unzoned');
        expect($quote->json('data.cents'))->toBeNull();
        expect($quote->json('data.zone.code'))->toBe('A1');
    });

    it('ignores a band that has been switched off', function (): void {
        $zone = ($this->createBand)()->json('data');

        $this->actingAs($this->accountant)
            ->patchJson("/api/v1/pricing/zones/{$zone['id']}", ['status' => 'inactive'])
            ->assertOk();

        expect(($this->quote)(['distance_km' => 20])->json('data.source'))->toBe('unzoned');
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

        expect($quote->json('data.zone.code'))->toBe('A1');
        expect($quote->json('data.card_cents'))->toBe(416_500);
    });

    it('offers the other band so a client can put the choice on screen', function (): void {
        $quote = ($this->quote)(['distance_km' => 30])->assertOk();

        expect($quote->json('data.zone_alternatives'))->toHaveCount(1);
        expect($quote->json('data.zone_alternatives.0.code'))->toBe('A2');
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

        expect($quote->json('data.diesel.pesos_above_baseline'))->toBe(42);
        expect($quote->json('data.fuel_surcharge_cents'))->toBe(58_800);
        expect($quote->json('data.cents'))->toBe(475_300);
        expect($quote->json('data.fuel_rule'))->toBe('step');
    });

    it('stays on the peso the table prints rather than between two columns', function (): void {
        ($this->diesel)(8_560)->assertCreated();

        expect(($this->quote)(['distance_km' => 20])->json('data.cents'))->toBe(475_300);
    });

    it('adds nothing for diesel inside the baseline band', function (): void {
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
        ($this->createBand)([
            'diesel_baseline_cents' => 6_500,
            'brackets' => [['label' => 'Current fleet', 'base_cents' => 185_000]],
        ]);

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

        $id = ($this->book)(['distance_total_m' => 0])->assertCreated()->json('data.id');
        expect(Trip::findOrFail($id)->price_cents)->toBe(416_500);

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

        expect($trip->price_cents)->toBe(0);
    });

    it('survives its band being deleted, keeping the price it was quoted', function (): void {
        $zone = ($this->createBand)()->json('data');

        $trip = Trip::findOrFail(($this->book)()->assertCreated()->json('data.id'));

        $this->actingAs($this->accountant)
            ->deleteJson("/api/v1/pricing/zones/{$zone['id']}")
            ->assertNoContent();

        expect($trip->refresh()->price_cents)->toBe(416_500);
        expect(PricingZone::withTrashed()->find($zone['id'])->code)->toBe('A1');
    });
});

describe('refusing a duplicate', function (): void {
    it('refuses a second band with the same code', function (): void {
        ($this->createBand)()->assertCreated();

        ($this->createBand)(['name' => 'Something else'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    });

    it('refuses a second band with the same name', function (): void {
        ($this->createBand)()->assertCreated();

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
