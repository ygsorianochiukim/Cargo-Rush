<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(NavigationSeeder::class);

    app(CompanyProvisioner::class)->provision($this->company);

    $this->admin = User::create([
        'name' => 'Owner', 'email' => 'owner@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]);

    $this->customer = Customer::create([
        'name' => 'Metro Grocers',
        'contact' => '0917 000 0001',
        'address' => '14 Corrales Ave, Cagayan de Oro',
        'tin' => '123-456-789-0000',
    ]);

    $this->actingAs($this->admin)->postJson('/api/v1/billing', [
        'customer_id' => $this->customer->id,
        'issued_at' => '2026-03-02',
        'due_at' => '2026-04-01',
        'amount_cents' => 1_000_000,
        'direction' => 'receivable',
    ])->assertCreated();

    $this->pdf = function (TestResponse $response, string $filename): void {
        $response->assertOk();

        expect($response->headers->get('content-type'))->toBe('application/pdf')
            ->and($response->headers->get('content-disposition'))->toContain($filename)
            ->and(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-');
    };
});

it('saves the income statement for the period on screen', function (): void {
    ($this->pdf)(
        $this->actingAs($this->admin)->get('/api/v1/accounting/statements/income.pdf?from=2026-01-01&to=2026-10-21'),
        'income-statement-2026-01-01-to-2026-10-21.pdf',
    );
});

it('saves the balance sheet as of the chosen date', function (): void {
    ($this->pdf)(
        $this->actingAs($this->admin)->get('/api/v1/accounting/statements/balance-sheet.pdf?as_of=2026-06-30'),
        'balance-sheet-2026-06-30.pdf',
    );
});

it('saves the aging report for the chosen direction', function (): void {
    ($this->pdf)(
        $this->actingAs($this->admin)->get('/api/v1/billing/aging.pdf?direction=payable'),
        'aging-payable-',
    );
});

it('saves one firm\'s statement of account', function (): void {
    ($this->pdf)(
        $this->actingAs($this->admin)
            ->get("/api/v1/billing/statement/{$this->customer->id}.pdf?from=2026-01-01&to=2026-10-21"),
        'statement-of-account-metro-grocers-2026-10-21.pdf',
    );
});

it('keeps each PDF behind the permission of the statement it prints', function (): void {
    $driver = User::create([
        'name' => 'Driver', 'email' => 'driver@test.test',
        'password' => 'password', 'role' => 'driver',
    ]);

    $this->actingAs($driver)->get('/api/v1/accounting/statements/income.pdf')->assertForbidden();
    $this->actingAs($driver)->get('/api/v1/accounting/statements/balance-sheet.pdf')->assertForbidden();
    $this->actingAs($driver)->get('/api/v1/billing/aging.pdf')->assertForbidden();
    $this->actingAs($driver)->get("/api/v1/billing/statement/{$this->customer->id}.pdf")->assertForbidden();
});
