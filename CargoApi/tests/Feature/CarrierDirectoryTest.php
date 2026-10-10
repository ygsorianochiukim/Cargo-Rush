<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\Demo\CarrierSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->company->update(['latitude' => 8.4856, 'longitude' => 124.5808]);

    $this->shopFirm = Customer::create([
        'name' => 'Highland Trading',
        'contact' => '0917 555 0333',
        'address' => 'Carmen, Cagayan de Oro',
        'latitude' => 8.4780,
        'longitude' => 124.6320,
    ]);

    $this->shopper = User::create([
        'name' => 'Rita Uy',
        'email' => 'rita@highlandtrading.ph',
        'password' => Hash::make('password1'),
        'role' => Role::Customer->value,
        'customer_id' => $this->shopFirm->id,
        'chooses_carrier' => true,
    ]);

    $this->neighbour = $this->makeCompany('Bay Coast Logistics');
    $this->neighbour->update([
        'latitude' => 8.2280,
        'longitude' => 124.2452,
        'address' => 'Tibanga Highway, Iligan City',
        'contact_phone' => '0917 555 0110',
    ]);

    $this->distant = $this->makeCompany('Northern Luzon Haulage');
    $this->distant->update(['latitude' => 14.5995, 'longitude' => 120.9842]);

    $this->carriers = fn (array $query = []) => $this->actingAs($this->shopper)
        ->getJson('/api/v1/portal/carriers?'.http_build_query($query));
});

describe('the carrier list', function (): void {
    it('offers the pinned, active hauliers nearest the load first', function (): void {
        $names = ($this->carriers)(['lat' => 8.4856, 'lng' => 124.5808])
            ->assertOk()
            ->json('data.*.name');

        expect($names)->toBe([$this->company->name, 'Bay Coast Logistics']);
    });

    it('measures the distance rather than guessing at it', function (): void {
        $rows = ($this->carriers)(['lat' => 8.4856, 'lng' => 124.5808])->json('data');

        expect($rows[0]['distance_km'])->toBeLessThan(0.1)
            ->and($rows[1]['distance_km'])->toBeGreaterThan(40.0)
            ->and($rows[1]['distance_km'])->toBeLessThan(60.0);
    });

    it('widens when asked to', function (): void {
        $names = ($this->carriers)(['lat' => 8.4856, 'lng' => 124.5808, 'radius_km' => 900])
            ->json('data.*.name');

        expect($names)->toContain('Northern Luzon Haulage');
    });

    it('hides a haulier that has not said where it is', function (): void {
        $unpinned = $this->makeCompany('Nowhere Freight');

        expect(($this->carriers)()->json('data.*.name'))->not->toContain('Nowhere Freight')
            ->and($unpinned->isPinned())->toBeFalse();
    });

    it('hides a suspended haulier', function (): void {
        $this->neighbour->update(['status' => StatusValue::Inactive->value]);

        expect(($this->carriers)()->json('data.*.name'))->not->toContain('Bay Coast Logistics');
    });

    it('says what each could put on the road, and nothing about their work', function (): void {
        $this->asCompany($this->neighbour, function (): void {
            Vehicle::create(['plate' => 'ILI 1120', 'model' => 'Isuzu Elf', 'registration_no' => 'R-1', 'capacity_kg' => 4000, 'status' => 'active']);
            Vehicle::create(['plate' => 'ILI 3345', 'model' => 'Hino 300', 'registration_no' => 'R-2', 'capacity_kg' => 3500, 'status' => 'available']);
            Vehicle::create(['plate' => 'ILI 8890', 'model' => 'Fuso', 'registration_no' => 'R-3', 'capacity_kg' => 6000, 'status' => 'maintenance']);
        });

        $card = collect(($this->carriers)()->json('data'))->firstWhere('name', 'Bay Coast Logistics');

        expect($card['vehicles_ready'])->toBe(2)
            ->and($card['capacity_kg'])->toBe(7500)
            ->and($card['contact_phone'])->toBe('0917 555 0110')
            ->and($card['address'])->toBe('Tibanga Highway, Iligan City')
            ->and(array_keys($card))->not->toContain('contact_email')
            ->and(array_keys($card))->not->toContain('trips');
    });

    it('is alphabetical when the customer did not say where they are', function (): void {
        $names = ($this->carriers)()->assertOk()->json('data.*.name');

        expect($names)->toBe(['Bay Coast Logistics', $this->company->name, 'Northern Luzon Haulage'])
            ->and(($this->carriers)()->json('data.0.distance_km'))->toBeNull();
    });

    it('finds one by name', function (): void {
        expect(($this->carriers)(['search' => 'Bay Coast'])->json('data.*.name'))
            ->toBe(['Bay Coast Logistics']);
    });

    it('refuses half a coordinate', function (): void {
        ($this->carriers)(['lat' => 8.4856])->assertStatus(422)
            ->assertJsonValidationErrors('lng');
    });

    it('is not open to a driver', function (): void {
        $driver = User::where('email', 'marco@cargorush.ph')->firstOrFail();

        $this->actingAs($driver)->getJson('/api/v1/portal/carriers')->assertForbidden();
    });
});

describe('filing with a carrier the shipper has never used', function (): void {
    beforeEach(function (): void {
        $this->file = fn (array $overrides = []) => $this->actingAs($this->shopper)
            ->postJson('/api/v1/portal/requests', [
                'origin' => 'Carmen, Cagayan de Oro',
                'destination' => 'Iligan',
                'cargo' => 'Chilled produce, 8 crates',
                'weight_kg' => 1800,
                'preferred_at' => now()->addDay()->toIso8601String(),
                ...$overrides,
            ]);
    });

    it('lands in that carrier books, not the shipper usual one', function (): void {
        $response = ($this->file)(['carrier_id' => $this->neighbour->id])->assertCreated();

        $trip = Trip::acrossCompanies()->findOrFail($response->json('data.id'));

        expect($trip->company_id)->toBe($this->neighbour->id)
            ->and($response->json('data.carrier'))->toBe('Bay Coast Logistics')
            ->and($response->json('data.status'))->toBe(StatusValue::Pending->value);
    });

    it('opens an account at that carrier, with their own terms', function (): void {
        ($this->file)(['carrier_id' => $this->neighbour->id])->assertCreated();

        $opened = $this->asCompany($this->neighbour, fn () => Customer::query()->get());

        expect($opened)->toHaveCount(1)
            ->and($opened->first()->name)->toBe('Highland Trading')
            ->and($opened->first()->address)->toBe('Carmen, Cagayan de Oro')
            ->and($opened->first()->isPinned())->toBeTrue()
            ->and($opened->first()->id)->not->toBe($this->shopFirm->id);
    });

    it('reuses that account on the next request rather than opening another', function (): void {
        ($this->file)(['carrier_id' => $this->neighbour->id])->assertCreated();
        ($this->file)(['carrier_id' => $this->neighbour->id])->assertCreated();

        expect($this->asCompany($this->neighbour, fn () => Customer::query()->count()))->toBe(1);
    });

    it('still goes to the usual carrier when none is named', function (): void {
        $id = ($this->file)()->assertCreated()->json('data.id');

        expect(Trip::acrossCompanies()->findOrFail($id)->company_id)->toBe($this->company->id);
    });

    it('refuses a carrier that is not taking requests', function (): void {
        $unpinned = $this->makeCompany('Nowhere Freight');

        ($this->file)(['carrier_id' => $unpinned->id])->assertNotFound();
    });

    it('refuses a suspended carrier, and says why', function (): void {
        $this->neighbour->update(['status' => StatusValue::Inactive->value]);

        ($this->file)(['carrier_id' => $this->neighbour->id])->assertStatus(422);
    });
});

describe('a shipper with two hauliers', function (): void {
    beforeEach(function (): void {
        $this->fileWith = fn (?string $carrierId) => $this->actingAs($this->shopper)
            ->postJson('/api/v1/portal/requests', array_filter([
                'carrier_id' => $carrierId,
                'origin' => 'Carmen, Cagayan de Oro',
                'destination' => 'Iligan',
                'cargo' => 'Chilled produce',
                'weight_kg' => 1800,
                'preferred_at' => now()->addDay()->toIso8601String(),
            ]));
    });

    it('sees both hauliers deliveries in one list, each named', function (): void {
        ($this->fileWith)(null);
        ($this->fileWith)($this->neighbour->id);

        $rows = $this->actingAs($this->shopper)->getJson('/api/v1/portal/requests')
            ->assertOk()
            ->json('data');

        expect($rows)->toHaveCount(2)
            ->and(collect($rows)->pluck('carrier')->sort()->values()->all())
            ->toBe(['Bay Coast Logistics', $this->company->name]);
    });

    it('can open a delivery held by either of them', function (): void {
        $mine = ($this->fileWith)(null)->json('data.id');
        $theirs = ($this->fileWith)($this->neighbour->id)->json('data.id');

        $this->actingAs($this->shopper)->getJson("/api/v1/portal/requests/{$mine}")->assertOk();

        $this->actingAs($this->shopper)->getJson("/api/v1/portal/requests/{$theirs}")
            ->assertOk()
            ->assertJsonPath('data.carrier', 'Bay Coast Logistics');
    });

    it('totals the home screen across them, and breaks it down by carrier', function (): void {
        ($this->fileWith)(null);
        ($this->fileWith)($this->neighbour->id);
        ($this->fileWith)($this->neighbour->id);

        $summary = $this->actingAs($this->shopper)->getJson('/api/v1/portal/summary')
            ->assertOk()
            ->json('data');

        expect($summary['awaiting_confirmation'])->toBe(3)
            ->and($summary['carriers'])->toHaveCount(2)
            ->and(collect($summary['carriers'])->firstWhere('name', 'Bay Coast Logistics')['awaiting_confirmation'])
            ->toBe(2);
    });

    it('keeps a haulier they use on the list wherever it is', function (): void {
        ($this->fileWith)($this->distant->id)->assertCreated();

        $card = collect(($this->carriers)(['lat' => 8.4856, 'lng' => 124.5808])->json('data'))
            ->firstWhere('name', 'Northern Luzon Haulage');

        expect($card)->not->toBeNull()
            ->and($card['linked'])->toBeTrue();
    });

    it('cannot read another firm work at a carrier they share', function (): void {
        $rivalTrip = $this->asCompany($this->neighbour, function (): Trip {
            $rival = Customer::create(['name' => 'Rival Trading', 'contact' => 'ops@rival.ph']);

            return Trip::create([
                'customer_id' => $rival->id,
                'origin' => 'Iligan',
                'destination' => 'Ozamis',
                'cargo' => 'Sacks of feed',
                'weight_kg' => 2400,
                'scheduled_at' => now()->addDay(),
            ]);
        });

        ($this->fileWith)($this->neighbour->id)->assertCreated();

        $this->actingAs($this->shopper)->getJson("/api/v1/portal/requests/{$rivalTrip->id}")
            ->assertNotFound();

        expect($this->actingAs($this->shopper)->getJson('/api/v1/portal/requests')->json('data.*.id'))
            ->not->toContain($rivalTrip->id);
    });
});

describe('the demo neighbours', function (): void {
    it('put a choice of carriers on the platform', function (): void {
        $this->company->update(['latitude' => null, 'longitude' => null]);

        $this->seed(CarrierSeeder::class);

        $names = ($this->carriers)(['lat' => 8.4856, 'lng' => 124.5808])->json('data.*.name');

        expect($names)->toContain('Bay Coast Logistics')
            ->and($names)->toContain('Bukidnon Highland Freight')
            ->and($names)->toContain($this->company->name);
    });

    it('runs twice without doubling the platform', function (): void {
        $this->seed(CarrierSeeder::class);
        $this->seed(CarrierSeeder::class);

        expect(Company::query()->where('code', 'bay-coast-logistics')->count())->toBe(1);
    });
});

describe('a haulier own pin', function (): void {
    it('is set at registration and puts the company on the list', function (): void {
        $this->postJson('/api/v1/register', [
            'company_name' => 'Sunrise Cargo',
            'address' => 'Puerto, Cagayan de Oro',
            'latitude' => 8.5089,
            'longitude' => 124.7290,
            'name' => 'Ana Cruz',
            'email' => 'ana@sunrisecargo.ph',
            'password' => 'sunrise-cargo-2026',
            'password_confirmation' => 'sunrise-cargo-2026',
            'device_name' => 'test-suite',
        ])->assertCreated();

        $registered = Company::query()->firstWhere('name', 'Sunrise Cargo');

        expect($registered->isPinned())->toBeTrue()
            ->and(($this->carriers)(['lat' => 8.4856, 'lng' => 124.5808])->json('data.*.name'))
            ->toContain('Sunrise Cargo');
    });

    it('refuses half a coordinate at registration', function (): void {
        $this->postJson('/api/v1/register', [
            'company_name' => 'Half Pinned Freight',
            'latitude' => 8.5089,
            'name' => 'Ana Cruz',
            'email' => 'ana@halfpinned.ph',
            'password' => 'half-pinned-2026',
            'password_confirmation' => 'half-pinned-2026',
            'device_name' => 'test-suite',
        ])->assertStatus(422)->assertJsonValidationErrors('longitude');
    });

    it('can be moved afterwards, which is how a firm that predates the list gets listed', function (): void {
        $admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

        $this->company->update(['latitude' => null, 'longitude' => null]);

        $this->actingAs($admin)->patchJson('/api/v1/company', [
            'latitude' => 8.4856,
            'longitude' => 124.5808,
            'address' => 'Iponan, Cagayan de Oro',
        ])
            ->assertOk()
            ->assertJsonPath('data.discoverable', true)
            ->assertJsonPath('data.latitude', 8.4856);

        expect(($this->carriers)()->json('data.*.name'))->toContain($this->company->name);
    });

    it('cannot be changed by somebody without the company permission', function (): void {
        $this->actingAs($this->shopper)->patchJson('/api/v1/company', ['latitude' => 0, 'longitude' => 0])
            ->assertForbidden();
    });
});
