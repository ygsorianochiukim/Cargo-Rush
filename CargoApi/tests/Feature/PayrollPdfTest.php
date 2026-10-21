<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->actingAs($this->admin)->postJson('/api/v1/employees', [
        'first_name' => 'Elena',
        'last_name' => 'Bautista',
        'position' => 'Office Staff',
        'department' => 'Administration',
        'contact' => '0917 555 0199',
        'hired_on' => '2026-01-05',
        'amount_cents' => 3_000_000,
        'sss_enrolled' => true,
        'philhealth_enrolled' => true,
        'pagibig_enrolled' => true,
    ])->assertCreated();

    $this->run = $this->actingAs($this->admin)->postJson('/api/v1/payroll', [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-15',
        'pay_date' => '2026-09-15',
    ])->assertCreated()->json('data');

    $this->pdf = fn (string $query = '') => $this->actingAs($this->admin)
        ->get("/api/v1/payroll/{$this->run['id']}/document.pdf{$query}");
});

it('downloads the whole run as a PDF register', function (): void {
    $response = ($this->pdf)()->assertOk();

    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain("payroll-{$this->run['reference']}.pdf")
        ->and(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-');
});

it('downloads one person\'s payslip', function (): void {
    $line = $this->run['lines'][0];

    $response = ($this->pdf)("?line={$line['id']}")->assertOk();

    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain("payslip-{$this->run['reference']}-elena-bautista.pdf")
        ->and(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-');
});

it('refuses a payslip for somebody not on the run', function (): void {
    ($this->pdf)('?line=01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
});

it('keeps it from anybody who cannot see payroll', function (): void {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);

    $this->actingAs($dispatcher)
        ->get("/api/v1/payroll/{$this->run['id']}/document.pdf")
        ->assertForbidden();
});
