<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use App\Domain\Tenancy\Support\Tenant;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);

    Storage::fake(config('cargo.company.disk'));

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]);
});

function logoOf(int $width, int $height): UploadedFile
{
    return UploadedFile::fake()->image('logo.png', $width, $height);
}

function storedLogo(string $path): array
{
    $size = getimagesizefromstring(Storage::disk(config('cargo.company.disk'))->get($path));

    return [$size[0], $size[1], $size['mime']];
}

it('stores a 64x64 PNG whatever was uploaded', function (int $w, int $h): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/company/logo', ['logo' => logoOf($w, $h)])
        ->assertOk();

    $path = $this->company->refresh()->logo_path;

    expect($path)->not->toBeNull()
        ->and(storedLogo($path))->toBe([64, 64, 'image/png']);
})->with([
    'a wide banner' => [900, 300],
    'a tall portrait' => [300, 900],
    'already square but huge' => [2400, 2400],
    'smaller than 64' => [24, 24],
]);

it('answers with the URL the client should render', function (): void {
    $body = $this->actingAs($this->admin)
        ->postJson('/api/v1/company/logo', ['logo' => logoOf(200, 200)])
        ->assertOk()
        ->json('data');

    expect($body['logo_url'])->toContain($this->company->refresh()->logo_path);

    $this->actingAs($this->admin)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.company_logo_url', $body['logo_url']);
});

it('removes the file it replaces', function (): void {
    $this->actingAs($this->admin)->postJson('/api/v1/company/logo', ['logo' => logoOf(200, 200)]);
    $first = $this->company->refresh()->logo_path;

    $this->actingAs($this->admin)->postJson('/api/v1/company/logo', ['logo' => logoOf(300, 300)]);
    $second = $this->company->refresh()->logo_path;

    expect($second)->not->toBe($first);

    Storage::disk(config('cargo.company.disk'))->assertMissing($first);
    Storage::disk(config('cargo.company.disk'))->assertExists($second);
});

it('takes the logo away, and the file with it', function (): void {
    $this->actingAs($this->admin)->postJson('/api/v1/company/logo', ['logo' => logoOf(200, 200)]);
    $path = $this->company->refresh()->logo_path;

    $this->actingAs($this->admin)->deleteJson('/api/v1/company/logo')
        ->assertOk()
        ->assertJsonPath('data.logo_url', null);

    expect($this->company->refresh()->logo_path)->toBeNull();
    Storage::disk(config('cargo.company.disk'))->assertMissing($path);
});

it('refuses a file that is not an image', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/company/logo', ['logo' => UploadedFile::fake()->create('books.pdf', 40, 'application/pdf')])
        ->assertStatus(422)
        ->assertJsonPath('errors.logo.0', 'The logo has to be an image — a PNG, JPEG, GIF or WebP.');

    expect($this->company->refresh()->logo_path)->toBeNull();
});

it('refuses a file larger than the configured ceiling', function (): void {
    config(['cargo.company.logo_max_kb' => 64]);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/company/logo', ['logo' => UploadedFile::fake()->image('huge.png')->size(2048)])
        ->assertStatus(422)
        ->assertJsonPath('errors.logo.0', 'That file is too large. Anything up to a few megabytes is fine.');
});

describe('who may change it', function (): void {
    it('turns away an account without company.manage', function (): void {
        $dispatcher = User::create([
            'name' => 'Desk', 'email' => 'desk@test.test',
            'password' => 'password', 'role' => 'dispatcher',
        ]);

        $this->actingAs($dispatcher)
            ->postJson('/api/v1/company/logo', ['logo' => logoOf(200, 200)])
            ->assertForbidden();

        $this->actingAs($dispatcher)->getJson('/api/v1/company')->assertForbidden();
    });

    it('sets it on the caller\'s company and no other', function (): void {
        $rival = $this->makeCompany('Rival Freight');
        app(CompanyProvisioner::class)->provision($rival);

        $theirs = $this->asCompany($rival, fn (): User => User::create([
            'name' => 'Their Owner', 'email' => 'owner@rival.test',
            'password' => 'password', 'role' => 'administrator',
        ]));

        $this->actingAs($theirs)
            ->postJson('/api/v1/company/logo', ['logo' => logoOf(200, 200)])
            ->assertOk();

        expect($rival->refresh()->logo_path)->not->toBeNull()
            ->and($this->company->refresh()->logo_path)->toBeNull();
    });
});

it('leaves a company with no logo reading as null rather than empty', function (): void {
    app(Tenant::class)->set($this->company);

    $this->actingAs($this->admin)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.company_logo_url', null);
});
