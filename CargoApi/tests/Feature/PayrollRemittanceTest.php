<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Identity\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * Recording that a paid run's contributions and tax reached the agencies.
 *
 *   **Off Payables from the day it was sent,** not at the presumed deadline.
 *
 *   **Posted as the reverse of what paying the run owed,** so the payables for
 *   SSS/PhilHealth/Pag-IBIG and withholding tax are square for that run.
 *
 *   **Once only,** and only on a paid run.
 */
beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    Carbon::setTestNow('2026-09-20 10:00:00');

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();

    $this->actingAs($this->admin)->postJson('/api/v1/employees', [
        'first_name' => 'Elena',
        'last_name' => 'Bautista',
        'position' => 'Office Staff',
        'department' => 'Administration',
        'contact' => '0917 555 0199',
        'hired_on' => '2026-01-05',
        'amount_cents' => 3_000_000,
    ])->assertCreated();

    $this->run = $this->actingAs($this->admin)->postJson('/api/v1/payroll', [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-15',
        'pay_date' => '2026-09-15',
    ])->assertCreated()->json('data');

    $this->approveAndPay = function (): array {
        $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$this->run['id']}/approve")->assertOk();

        return $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$this->run['id']}/pay")->assertOk()->json('data');
    };

    $this->remit = fn (array $payload = []) => $this->actingAs($this->admin)
        ->postJson("/api/v1/payroll/{$this->run['id']}/remit", [
            'remitted_on' => '2026-09-18',
            'reference' => 'SSS-PRN 12345',
            ...$payload,
        ]);

    $this->statutoryLine = fn () => collect(
        $this->actingAs($this->admin)->getJson('/api/v1/finance/payables')->json('data.groups'),
    )->firstWhere('key', 'payroll')['lines'] ?? [];
});

afterEach(fn () => Carbon::setTestNow());

it('takes the run off Payables once the remittance is recorded', function (): void {
    $paid = ($this->approveAndPay)();

    expect($paid['owed_to_agencies_cents'])->toBeGreaterThan(0)
        ->and($paid['remittance_due_on'])->toBe('2026-10-31')
        ->and($paid['remitted_on'])->toBeNull();

    $before = ($this->statutoryLine)();
    expect($before)->toHaveCount(1)
        ->and($before[0]['id'])->toBe($this->run['id'].':statutory')
        ->and($before[0]['amount_cents'])->toBe($paid['owed_to_agencies_cents']);

    ($this->remit)()->assertOk()
        ->assertJsonPath('data.remitted_on', '2026-09-18')
        ->assertJsonPath('data.remittance_reference', 'SSS-PRN 12345');

    expect(($this->statutoryLine)())->toBe([]);
});

it('posts the remittance as the reverse of what paying the run owed', function (): void {
    $paid = ($this->approveAndPay)();
    ($this->remit)()->assertOk();

    $entry = JournalEntry::query()
        ->where('source_id', $this->run['id'])
        ->where('source_rule', 'remittance')
        ->with('lines.account')
        ->firstOrFail();

    $by = fn (string $code, string $side) => (int) $entry->lines
        ->filter(fn ($line) => $line->account->code === $code)
        ->sum("{$side}_cents");

    expect($entry->entry_date->toDateString())->toBe('2026-09-18')
        ->and($by('1020', 'credit'))->toBe($paid['owed_to_agencies_cents'])
        ->and($by('2200', 'debit') + $by('2160', 'debit'))->toBe($paid['owed_to_agencies_cents']);

    // Across both of the run's entries, the payables it raised are square.
    $net = fn (string $code) => (int) JournalEntry::query()
        ->where('source_id', $this->run['id'])
        ->with('lines.account')
        ->get()
        ->flatMap->lines
        ->filter(fn ($line) => $line->account->code === $code)
        ->sum(fn ($line) => $line->credit_cents - $line->debit_cents);

    expect($net('2200'))->toBe(0)->and($net('2160'))->toBe(0);
});

it('refuses a remittance on a run that has not been paid', function (): void {
    ($this->remit)()->assertStatus(422);

    $this->actingAs($this->admin)->postJson("/api/v1/payroll/{$this->run['id']}/approve")->assertOk();
    ($this->remit)()->assertStatus(422);
});

it('records a remittance only once', function (): void {
    ($this->approveAndPay)();
    ($this->remit)()->assertOk();

    ($this->remit)(['remitted_on' => '2026-09-19'])->assertStatus(422);

    expect(JournalEntry::query()->where('source_rule', 'remittance')->count())->toBe(1);
});

it('refuses a remittance dated in the future', function (): void {
    ($this->approveAndPay)();

    ($this->remit)(['remitted_on' => '2026-09-25'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('remitted_on');
});

it('numbers each remittance REM-YYYY-#### automatically', function (): void {
    expect($this->actingAs($this->admin)->getJson('/api/v1/payroll/remittance-number')->json('data.remittance_no'))
        ->toBe('REM-2026-0001');

    ($this->approveAndPay)();

    // No agency reference given — the number is still assigned.
    $run = ($this->remit)(['reference' => null])->assertOk()->json('data');

    expect($run['remittance_no'])->toBe('REM-2026-0001')
        ->and($run['remittance_reference'])->toBeNull();

    expect($this->actingAs($this->admin)->getJson('/api/v1/payroll/remittance-number')->json('data.remittance_no'))
        ->toBe('REM-2026-0002');

    $entry = JournalEntry::query()->where('source_rule', 'remittance')->firstOrFail();
    expect($entry->memo)->toStartWith('REM-2026-0001');
});

it('keeps the agency reference beside our own number', function (): void {
    ($this->approveAndPay)();

    $run = ($this->remit)()->assertOk()->json('data');

    expect($run['remittance_no'])->toBe('REM-2026-0001')
        ->and($run['remittance_reference'])->toBe('SSS-PRN 12345');
});
