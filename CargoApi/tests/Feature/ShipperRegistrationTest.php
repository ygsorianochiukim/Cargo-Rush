<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Shared\Enums\Role;
use App\Domain\Trip\Models\Trip;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\Demo\OperationsSeeder;
use Database\Seeders\NavigationSeeder;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);

    $this->company->update([
        'latitude' => 8.4856,
        'longitude' => 124.5808,
        'address' => 'Iponan, Cagayan de Oro',
        'contact_phone' => '0917 555 0100',
    ]);

    $this->rival = $this->makeCompany('Bay Coast Logistics');
    $this->rival->update(['latitude' => 8.2280, 'longitude' => 124.2452]);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->officeCustomer = User::where('email', 'orders@negrosfresh.ph')->firstOrFail();

    $this->signUp = fn (array $overrides = []) => $this->postJson('/api/v1/register/customer', [
        'name' => 'Rita Uy',
        'contact_phone' => '0917 555 0333',
        'email' => 'rita@highlandtrading.ph',
        'password' => 'highland-trading-2026',
        'password_confirmation' => 'highland-trading-2026',
        'device_name' => 'test-suite',
        ...$overrides,
    ]);

    $this->pickup = fn (array $overrides = []) => [
        'origin' => 'Carmen, Cagayan de Oro',
        'destination' => 'Iligan',
        'cargo' => 'Chilled produce',
        'weight_kg' => 1800,
        'preferred_at' => now()->addDay()->toIso8601String(),
        ...$overrides,
    ];
});

describe('the carrier directory', function (): void {
    it('is answered without signing in', function (): void {
        $names = $this->getJson('/api/v1/carriers?lat=8.4856&lng=124.5808')
            ->assertOk()
            ->json('data.*.name');

        expect($names)->toBe([$this->company->name, 'Bay Coast Logistics']);
    });

    it('shows a stranger only what a haulier paints on a truck', function (): void {
        $card = $this->getJson('/api/v1/carriers')->json('data.0');

        expect($card)->toHaveKeys(['name', 'address', 'contact_phone', 'vehicles_ready', 'capacity_kg'])
            ->and($card['linked'])->toBeFalse()
            ->and(array_keys($card))->not->toContain('contact_email');
    });
});

describe('a shipper signing themselves up', function (): void {
    it('lands signed in, as a customer of nobody yet', function (): void {
        $response = ($this->signUp)()->assertCreated();

        expect($response->json('data.role'))->toBe(Role::Customer->value)
            ->and($response->json('data.name'))->toBe('Rita Uy')
            ->and($response->json('data.company_id'))->toBeNull()
            ->and($response->json('data.company_name'))->toBeNull()
            ->and($response->json('data.customer_id'))->toBeNull()
            ->and($response->json('meta.token'))->toBeString()
            ->and($response->json('data.chooses_carrier'))->toBeTrue();
    });

    it('asks for nothing but a person and a login', function (): void {
        ($this->signUp)([
            'carrier_id' => $this->rival->id,
            'address' => 'Carmen, Cagayan de Oro',
            'latitude' => 8.4780,
            'longitude' => 124.6320,
            'business_name' => 'Highland Trading Corporation',
        ])->assertCreated();

        $login = User::acrossCompanies()->firstWhere('email', 'rita@highlandtrading.ph');

        expect($login->company_id)->toBeNull()
            ->and($login->name)->toBe('Rita Uy')
            ->and($login->phone)->toBe('0917 555 0333')
            ->and(Customer::acrossCompanies()->where('name', 'Rita Uy')->exists())->toBeFalse();
    });

    it('appears on nobody books until they choose', function (): void {
        ($this->signUp)();

        expect(Customer::acrossCompanies()->where('name', 'Rita Uy')->exists())->toBeFalse()
            ->and(
                NotificationItem::acrossCompanies()
                    ->where('title', 'New customer registered')
                    ->exists()
            )->toBeFalse();
    });

    it('can read the carriers near the load, signed in', function (): void {
        $token = ($this->signUp)()->json('meta.token');

        $names = $this->withToken($token)->getJson('/api/v1/portal/carriers?lat=8.4856&lng=124.5808')
            ->assertOk()
            ->json('data.*.name');

        expect($names)->toBe([$this->company->name, 'Bay Coast Logistics']);
    });

    it('is shown an empty portal rather than an error', function (): void {
        $token = ($this->signUp)()->json('meta.token');

        $summary = $this->withToken($token)->getJson('/api/v1/portal/summary')->assertOk();

        expect($summary->json('data.awaiting_confirmation'))->toBe(0)
            ->and($summary->json('data.carriers'))->toBe([])
            ->and($summary->json('data.customer.id'))->toBeNull();

        $this->withToken($token)->getJson('/api/v1/portal/requests')->assertOk()
            ->assertJsonPath('data', []);
        $this->withToken($token)->getJson('/api/v1/portal/invoices')->assertOk()
            ->assertJsonPath('data', []);
    });

    it('reads nobody books while it belongs to nobody', function (): void {
        $this->seed(OperationsSeeder::class);

        expect(Trip::acrossCompanies()->count())->toBeGreaterThan(0);

        $token = ($this->signUp)()->json('meta.token');

        $this->withToken($token)->getJson('/api/v1/portal/requests')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->withToken($token)->getJson('/api/v1/portal/summary')
            ->assertOk()
            ->assertJsonPath('data.in_transit', 0)
            ->assertJsonPath('data.carriers', []);
    });

    it('becomes a customer of the haulier they pick on the first request', function (): void {
        $token = ($this->signUp)()->json('meta.token');

        $this->withToken($token)
            ->postJson('/api/v1/portal/requests', ($this->pickup)(['carrier_id' => $this->company->id]))
            ->assertCreated()
            ->assertJsonPath('data.carrier', $this->company->name);

        $firm = $this->asCompany($this->company, fn () => Customer::query()->firstWhere('name', 'Rita Uy'));

        expect($firm)->not->toBeNull()
            ->and($firm->company_id)->toBe($this->company->id)
            ->and($firm->contact)->toBe('0917 555 0333')
            ->and($firm->address)->toBeNull()
            ->and($firm->isPinned())->toBeFalse();
    });

    it('belongs to that haulier from then on', function (): void {
        $token = ($this->signUp)()->json('meta.token');

        $this->withToken($token)
            ->postJson('/api/v1/portal/requests', ($this->pickup)(['carrier_id' => $this->company->id]))
            ->assertCreated();

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.company_name', $this->company->name)
            ->assertJsonPath('data.customer_name', 'Rita Uy')
            ->assertJsonPath('data.chooses_carrier', true);
    });

    it('tells that carrier desk, and not their drivers', function (): void {
        $token = ($this->signUp)()->json('meta.token');

        $this->withToken($token)
            ->postJson('/api/v1/portal/requests', ($this->pickup)(['carrier_id' => $this->company->id]))
            ->assertCreated();

        $told = $this->asCompany(
            $this->company,
            fn () => NotificationItem::query()->where('title', 'New customer registered')->pluck('user_id'),
        );

        expect($told)->toContain($this->admin->id)
            ->and($told->contains(null))->toBeFalse();
    });

    it('has to say who should carry the first load', function (): void {
        $token = ($this->signUp)()->json('meta.token');

        $this->withToken($token)->postJson('/api/v1/portal/requests', ($this->pickup)())
            ->assertStatus(422);

        expect(Trip::acrossCompanies()->where('cargo', 'Chilled produce')->exists())->toBeFalse();
    });

    it('cannot send to a haulier that is not taking requests', function (): void {
        $unpinned = $this->makeCompany('Nowhere Freight');
        $token = ($this->signUp)()->json('meta.token');

        $this->withToken($token)
            ->postJson('/api/v1/portal/requests', ($this->pickup)(['carrier_id' => $unpinned->id]))
            ->assertNotFound();

        expect(Customer::acrossCompanies()->where('name', 'Rita Uy')->exists())->toBeFalse();
    });

    it('can pick a different haulier for the next load', function (): void {
        $token = ($this->signUp)()->json('meta.token');

        $this->withToken($token)
            ->postJson('/api/v1/portal/requests', ($this->pickup)(['carrier_id' => $this->company->id]))
            ->assertCreated();

        $this->withToken($token)->postJson('/api/v1/portal/requests', ($this->pickup)([
            'carrier_id' => $this->rival->id,
            'destination' => 'Ozamis',
            'cargo' => 'Sacks of feed',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.carrier', 'Bay Coast Logistics');

        $second = $this->asCompany($this->rival, fn () => Customer::query()->firstWhere('name', 'Rita Uy'));

        expect($second->contact)->toBe('0917 555 0333');
    });

    it('files a later request with the carrier they started on', function (): void {
        $token = ($this->signUp)()->json('meta.token');

        $this->withToken($token)
            ->postJson('/api/v1/portal/requests', ($this->pickup)(['carrier_id' => $this->company->id]))
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/v1/portal/requests', ($this->pickup)(['cargo' => 'Boxed goods']))
            ->assertCreated()
            ->assertJsonPath('data.carrier', $this->company->name);
    });

    it('refuses an address that already has an account', function (): void {
        ($this->signUp)(['email' => 'orders@negrosfresh.ph'])->assertStatus(422)
            ->assertJsonValidationErrors('email');

        expect(User::query()->where('name', 'Rita Uy')->exists())->toBeFalse();
    });

    it('needs a name to be booked under', function (): void {
        ($this->signUp)(['name' => ''])->assertStatus(422)
            ->assertJsonValidationErrors('name');
    });
});

describe('a customer the office added', function (): void {
    it('is shown no other haulier at all', function (): void {
        $this->actingAs($this->officeCustomer)->getJson('/api/v1/portal/carriers')
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, $this->company->name));
    });

    it('cannot send a load to a different company by asking', function (): void {
        $this->actingAs($this->officeCustomer)->postJson('/api/v1/portal/requests', [
            'carrier_id' => $this->rival->id,
            'origin' => 'Bacolod',
            'destination' => 'Iloilo',
            'cargo' => 'Chilled produce',
            'weight_kg' => 1800,
            'preferred_at' => now()->addDay()->toIso8601String(),
        ])->assertForbidden();

        expect(Trip::acrossCompanies()->where('cargo', 'Chilled produce')->exists())->toBeFalse();
    });

    it('books with their own haulier exactly as it always did', function (): void {
        $response = $this->actingAs($this->officeCustomer)->postJson('/api/v1/portal/requests', [
            'origin' => 'Bacolod',
            'destination' => 'Iloilo',
            'cargo' => 'Chilled produce',
            'weight_kg' => 1800,
            'preferred_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated();

        expect($response->json('data.carrier'))->toBe($this->company->name)
            ->and($this->officeCustomer->choosesCarrier())->toBeFalse();
    });

    it('may name their own haulier without being refused for it', function (): void {
        $this->actingAs($this->officeCustomer)->postJson('/api/v1/portal/requests', [
            'carrier_id' => $this->company->id,
            'origin' => 'Bacolod',
            'destination' => 'Iloilo',
            'cargo' => 'Boxed goods',
            'weight_kg' => 900,
            'preferred_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated();
    });
});
