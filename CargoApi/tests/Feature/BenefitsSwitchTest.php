<?php

declare(strict_types=1);

use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * The firm-wide benefits switch, and choosing who is on benefits.
 *
 * What these are defending:
 *
 *   **Off means nobody.** No SSS, PhilHealth or Pag-IBIG on any payslip —
 *   employee or employer share — whatever the person's own record says.
 *
 *   **Off does not rewrite anybody.** Each person's flags survive, so turning
 *   benefits back on returns everybody to the enrolment they had.
 *
 *   **Tax is not a benefit.** Withholding still comes off with benefits off.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->hire = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/employees', [
            'first_name' => 'Elena',
            'last_name' => 'Bautista',
            'position' => 'Office Staff',
            'department' => 'Administration',
            'contact' => '0917 555 0199',
            'hired_on' => '2026-01-05',
            'amount_cents' => 3_000_000,
            ...$overrides,
        ])->assertCreated()->json('data');

    $this->benefits = fn (bool $on) => $this->actingAs($this->admin)
        ->patchJson('/api/v1/company', ['payroll_benefits_enabled' => $on]);

    $this->open = fn () => $this->actingAs($this->admin)
        ->postJson('/api/v1/payroll', [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'pay_date' => '2026-09-15',
        ]);
});

it('has benefits on by default, and saves the switch', function (): void {
    expect($this->actingAs($this->admin)->getJson('/api/v1/company')->json('data.payroll_benefits_enabled'))
        ->toBeTrue();

    ($this->benefits)(false)->assertOk()->assertJsonPath('data.payroll_benefits_enabled', false);
});

it('rejects a switch that is not yes or no', function (): void {
    $this->actingAs($this->admin)
        ->patchJson('/api/v1/company', ['payroll_benefits_enabled' => 'sometimes'])
        ->assertUnprocessable();
});

it('takes no contribution from anybody with benefits off, but still withholds tax', function (): void {
    $employee = ($this->hire)();
    ($this->benefits)(false)->assertOk();

    $line = ($this->open)()->assertCreated()->json('data.lines.0');

    expect($line['sss_cents'])->toBe(0)
        ->and($line['philhealth_cents'])->toBe(0)
        ->and($line['pagibig_cents'])->toBe(0)
        ->and($line['employer_sss_cents'])->toBe(0)
        ->and($line['employer_contributions_cents'])->toBe(0)
        ->and($line['sss_enrolled'])->toBeFalse()
        ->and($line['withholding_tax_cents'])->toBeGreaterThan(0);

    // The person's own record is untouched.
    expect(Employee::find($employee['id'])->statutoryEnrolment())
        ->toBe(['sss' => true, 'philhealth' => true, 'pagibig' => true]);
});

it('returns everybody to their own enrolment when benefits go back on', function (): void {
    ($this->hire)(['pagibig_enrolled' => false]);
    ($this->benefits)(false)->assertOk();
    ($this->benefits)(true)->assertOk();

    $line = ($this->open)()->assertCreated()->json('data.lines.0');

    expect($line['sss_cents'])->toBeGreaterThan(0)
        ->and($line['philhealth_cents'])->toBeGreaterThan(0)
        ->and($line['pagibig_cents'])->toBe(0);
});

it('gives benefits only to the people chosen', function (): void {
    $with = ($this->hire)();
    $without = ($this->hire)(['first_name' => 'Jun', 'last_name' => 'Santos']);

    // What the Access Control card sends for somebody taken off benefits.
    $this->actingAs($this->admin)->patchJson("/api/v1/employees/{$without['id']}", [
        'sss_enrolled' => false,
        'philhealth_enrolled' => false,
        'pagibig_enrolled' => false,
    ])->assertOk();

    $lines = collect(($this->open)()->assertCreated()->json('data.lines'))->keyBy('employee_id');

    expect($lines[$with['id']]['sss_cents'])->toBeGreaterThan(0)
        ->and($lines[$without['id']]['sss_cents'])->toBe(0)
        ->and($lines[$without['id']]['philhealth_cents'])->toBe(0)
        ->and($lines[$without['id']]['pagibig_cents'])->toBe(0)
        ->and($lines[$without['id']]['employer_contributions_cents'])->toBe(0);

    // A partial edit leaves the rest of the record alone.
    expect(Employee::find($without['id'])->first_name)->toBe('Jun');
});

it('pages and searches the staff list the benefits card reads', function (): void {
    foreach (['Ana', 'Ben', 'Carla'] as $name) {
        ($this->hire)(['first_name' => $name, 'last_name' => 'Reyes']);
    }
    ($this->hire)(['first_name' => 'Dario', 'last_name' => 'Cruz']);

    $page = $this->actingAs($this->admin)
        ->getJson('/api/v1/employees?status=active&search=Reyes&per_page=2&page=2')
        ->assertOk();

    expect($page->json('meta.total'))->toBe(3)
        ->and($page->json('meta.per_page'))->toBe(2)
        ->and($page->json('data'))->toHaveCount(1)
        ->and($page->json('data.0'))->toHaveKeys(['sss_enrolled', 'philhealth_enrolled', 'pagibig_enrolled']);
});
