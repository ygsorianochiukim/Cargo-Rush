<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);

    $this->rival = $this->makeCompany('Rival Freight');
});

function ownerOf(Company $company): User
{
    app(CompanyProvisioner::class)->provision($company);

    return test()->asCompany($company, fn (): User => User::create([
        'name' => 'Owner of '.$company->name,
        'email' => 'owner@'.$company->code.'.test',
        'password' => 'password',
        'role' => 'administrator',
    ]));
}

describe('reading', function (): void {
    it('does not show one company the fleet of another', function (): void {
        $mine = ownerOf($this->company);

        $this->asCompany($this->rival, function (): void {
            Vehicle::create([
                'plate' => 'RIVAL 001', 'model' => 'Isuzu Forward',
                'registration_no' => 'LTO-1', 'capacity_kg' => 8000,
            ]);
            Driver::create([
                'name' => 'Their Driver', 'licence_no' => 'R-1',
                'licence_expiry' => now()->addYear()->toDateString(),
            ]);
            Customer::create(['name' => 'Their Customer', 'contact' => '0917']);
        });

        $this->actingAs($mine)->getJson('/api/v1/vehicles')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($mine)->getJson('/api/v1/drivers')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($mine)->getJson('/api/v1/customers')->assertOk()->assertJsonCount(0, 'data');
    });

    it('answers 404, not 200, for a record belonging to another company', function (): void {
        $mine = ownerOf($this->company);

        $theirs = $this->asCompany($this->rival, fn (): Vehicle => Vehicle::create([
            'plate' => 'RIVAL 002', 'model' => 'Fuso Canter',
            'registration_no' => 'LTO-2', 'capacity_kg' => 6000,
        ]));

        $this->actingAs($mine)->getJson("/api/v1/vehicles/{$theirs->id}")->assertNotFound();
    });

    it('ignores a company_id sent by the client', function (): void {
        $mine = ownerOf($this->company);

        $this->actingAs($mine)->postJson('/api/v1/customers', [
            'name' => 'Planted Customer',
            'contact' => '0917 000 0000',
            'company_id' => $this->rival->id,
        ])->assertCreated();

        expect(Customer::acrossCompanies()->where('name', 'Planted Customer')->value('company_id'))
            ->toBe($this->company->id);
    });
});

describe('writing', function (): void {
    it('stamps a new row with the company that made it', function (): void {
        $mine = ownerOf($this->company);

        $this->actingAs($mine)->postJson('/api/v1/vehicles', [
            'plate' => 'MINE 001', 'model' => 'Isuzu Forward',
            'registration_no' => 'LTO-3', 'capacity_kg' => 8000,
        ])->assertCreated();

        expect(Vehicle::acrossCompanies()->where('plate', 'MINE 001')->value('company_id'))
            ->toBe($this->company->id);
    });

    it('refuses to create a row with no company at all', function (): void {
        app(Tenant::class)->forget();

        expect(fn () => Customer::create(['name' => 'Nobody', 'contact' => '0917']))
            ->toThrow(RuntimeException::class, 'Refusing to create');
    });
});

describe('per-company configuration', function (): void {
    it('gives each company its own roles and leaves the neighbour alone', function (): void {
        $mine = ownerOf($this->company);
        ownerOf($this->rival);

        $dispatcher = Role::where('key', 'dispatcher')->firstOrFail();

        $this->actingAs($mine)->patchJson("/api/v1/access/roles/{$dispatcher->id}", [
            'name' => 'Yard Controller',
        ])->assertOk();

        $theirs = $this->asCompany(
            $this->rival,
            fn (): Role => Role::where('key', 'dispatcher')->firstOrFail(),
        );

        expect($theirs->name)->toBe('Dispatcher');
    });

    it('lets two companies use the same role key, plate and payroll number', function (): void {
        ownerOf($this->company);
        ownerOf($this->rival);

        expect(Role::acrossCompanies()->where('key', 'administrator')->count())->toBe(2);

        Vehicle::create([
            'plate' => 'SHARED 01', 'model' => 'Isuzu Forward',
            'registration_no' => 'LTO-4', 'capacity_kg' => 8000,
        ]);

        $this->asCompany($this->rival, fn () => Vehicle::create([
            'plate' => 'SHARED 01', 'model' => 'Fuso Canter',
            'registration_no' => 'LTO-5', 'capacity_kg' => 6000,
        ]));

        expect(Vehicle::acrossCompanies()->where('plate', 'SHARED 01')->count())->toBe(2);
    });

    it('starts each company\'s trip references from the beginning', function (): void {
        $customer = Customer::create(['name' => 'Mine', 'contact' => '0917']);

        $first = Trip::create([
            'customer_id' => $customer->id, 'origin' => 'A', 'destination' => 'B',
            'cargo' => 'Boxes', 'weight_kg' => 100, 'scheduled_at' => now()->addDay(),
        ]);

        $theirs = $this->asCompany($this->rival, fn (): Trip => Trip::create([
            'origin' => 'C', 'destination' => 'D',
            'cargo' => 'Crates', 'weight_kg' => 200, 'scheduled_at' => now()->addDay(),
        ]));

        $expected = 'CTS-'.now()->setTimezone('Asia/Manila')->format('mdy').'-00001';

        expect($first->reference)->toBe($expected)
            ->and($theirs->reference)->toBe($expected);
    });
});

describe('registration', function (): void {
    it('creates the company, its configuration and its first account in one call', function (): void {
        $this->postJson('/api/v1/register', [
            'company_name' => 'Southern Freight Services',
            'name' => 'Ana Cruz',
            'email' => 'ana@southern.test',
            'password' => 'Str0ngPassw0rd!',
            'password_confirmation' => 'Str0ngPassw0rd!',
            'device_name' => 'test',
        ])
            ->assertCreated()
            ->assertJsonPath('data.company_name', 'Southern Freight Services')
            ->assertJsonPath('data.company_code', 'southern-freight-services')
            ->assertJsonPath('data.role', 'administrator')
            ->assertJsonPath('data.permissions', ['*'])
            ->assertJsonStructure(['meta' => ['token']]);

        $company = Company::where('code', 'southern-freight-services')->firstOrFail();

        $this->asCompany($company, function (): void {
            expect(Role::count())->toBeGreaterThan(0)
                ->and(Position::count())->toBeGreaterThan(0)
                ->and(ExpenseCategory::count())->toBeGreaterThan(0);
        });
    });

    it('lays down the platform configuration on a database nobody has seeded', function (): void {
        DB::table('permission_role')->delete();
        Permission::query()->delete();
        DB::table('nav_items')->delete();

        expect(Permission::count())->toBe(0)
            ->and(DB::table('nav_items')->count())->toBe(0);

        $this->postJson('/api/v1/register', [
            'company_name' => 'Sunrise Cargo',
            'name' => 'Ana Cruz',
            'email' => 'ana@sunrise.test',
            'password' => 'Str0ngPassw0rd!',
            'password_confirmation' => 'Str0ngPassw0rd!',
            'device_name' => 'test',
        ])->assertCreated();

        expect(Permission::count())->toBeGreaterThan(0)
            ->and(DB::table('nav_items')->count())->toBeGreaterThan(0);

        $company = Company::where('code', 'sunrise-cargo')->firstOrFail();

        $this->asCompany($company, function (): void {
            $customer = Role::where('key', 'customer')->firstOrFail();

            expect($customer->permissions()->pluck('key')->all())->toContain('portal.view');
        });
    });

    it('reads the new owner\'s role from their own company, not a neighbour\'s', function (): void {
        ownerOf($this->rival);
        $this->asCompany($this->rival, function (): void {
            Role::where('key', 'administrator')->firstOrFail()
                ->update(['all_permissions' => false]);
        });

        $token = $this->postJson('/api/v1/register', [
            'company_name' => 'Northern Haulage',
            'name' => 'Ben Uy',
            'email' => 'ben@northern.test',
            'password' => 'Str0ngPassw0rd!',
            'password_confirmation' => 'Str0ngPassw0rd!',
            'device_name' => 'test',
        ])
            ->assertCreated()
            ->assertJsonPath('data.permissions', ['*'])
            ->json('meta.token');

        $this->withToken($token)->getJson('/api/v1/access/roles')->assertOk();
    });

    it('refuses an email address that already has an account anywhere', function (): void {
        $this->asCompany($this->rival, fn () => User::create([
            'name' => 'Taken', 'email' => 'taken@example.test',
            'password' => 'password', 'role' => 'administrator',
        ]));

        $this->postJson('/api/v1/register', [
            'company_name' => 'Another Firm',
            'name' => 'Someone Else',
            'email' => 'taken@example.test',
            'password' => 'Str0ngPassw0rd!',
            'password_confirmation' => 'Str0ngPassw0rd!',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'That address already has an account. Sign in instead.');
    });

    it('suffixes the code when the name is already taken', function (): void {
        foreach (['first@a.test', 'second@b.test'] as $email) {
            $this->postJson('/api/v1/register', [
                'company_name' => 'Southern Freight',
                'name' => 'Owner',
                'email' => $email,
                'password' => 'Str0ngPassw0rd!',
                'password_confirmation' => 'Str0ngPassw0rd!',
                'device_name' => 'test',
            ])->assertCreated();
        }

        expect(Company::where('name', 'Southern Freight')->pluck('code')->sort()->values()->all())
            ->toBe(['southern-freight', 'southern-freight-2']);
    });
});

describe('signing in', function (): void {
    it('opens the company the account belongs to, with no company field to send', function (): void {
        $mine = ownerOf($this->company);

        $this->postJson('/api/v1/login', [
            'email' => $mine->email,
            'password' => 'password',
            'device_name' => 'test',
        ])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->company->id);
    });

    it('turns away a suspended company and says why', function (): void {
        $mine = ownerOf($this->company);

        $this->company->update(['status' => StatusValue::Inactive->value]);

        $this->postJson('/api/v1/login', [
            'email' => $mine->email,
            'password' => 'password',
            'device_name' => 'test',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', "{$this->company->name} is suspended. Contact support to reactivate the account.");

        expect(Customer::acrossCompanies()->count())->toBe(0);
    });

    it('still lets a suspended company sign out', function (): void {
        $mine = ownerOf($this->company);
        $this->company->update(['status' => StatusValue::Inactive->value]);

        $this->actingAs($mine)->postJson('/api/v1/logout')->assertNoContent();
        $this->actingAs($mine)->getJson('/api/v1/me')->assertForbidden();
    });
});
