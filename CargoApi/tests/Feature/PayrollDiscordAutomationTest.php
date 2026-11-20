<?php

declare(strict_types=1);

use App\Domain\Assistant\Services\AssistantService;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Payroll\Models\PayrollDiscordAlert;
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    app(CompanyProvisioner::class)->provision($this->company);
    $this->admin = User::create([
        'name' => 'Payroll Admin', 'email' => 'noon-payroll@test.test',
        'password' => 'password', 'role' => 'administrator',
    ]);
    $this->hrChannel = '111111111111111111';
    $this->assistant = app(AssistantService::class);
    $this->assistant->update(['enabled' => true, 'discord_hr_channel_id' => $this->hrChannel]);
});

it('uses a planned pay date override and generates both drafts once at their due date', function (): void {
    $this->travelTo(Carbon::parse('2026-11-21 12:00', 'Asia/Manila'));
    $this->actingAs($this->admin)->patchJson('/api/v1/company', [
        'payroll_cutoff_days' => [5, 15, 25], 'payroll_release_lag_days' => 3,
        'payroll_pay_dates' => ['2026-11-15' => '2026-11-22'],
        'payroll_auto_drafts' => true,
    ])->assertOk()
        ->assertJsonPath('data.payroll_calendar.example_periods.1.release_on', '2026-11-22');

    $this->postJson('/api/v1/employees', [
        'first_name' => 'Elena', 'last_name' => 'Bautista',
        'position' => 'Office Staff', 'hired_on' => '2026-01-05', 'amount_cents' => 3_000_000, 'contact' => '0917 555 0101',
    ])->assertCreated();
    $crew = $this->postJson('/api/v1/drivers', [
        'role' => 'driver', 'employment' => 'company', 'name' => 'Marco Villanueva',
        'contact' => '0917 555 0102', 'licence_no' => 'N01-23-456789', 'licence_expiry' => '2029-08-31',
    ])->assertCreated()->json('data');
    payContract(Employee::findOrFail($crew['employee_id']), 'per_trip', 150_000, '2026-01-05');

    $this->artisan('cargo:payroll-drafts')->assertSuccessful();
    expect(PayRun::count())->toBe(0);

    $this->travelTo(Carbon::parse('2026-11-22 12:00', 'Asia/Manila'));
    $this->artisan('cargo:payroll-drafts')->assertSuccessful();
    $this->artisan('cargo:payroll-drafts')->assertSuccessful();
    $runs = PayRun::with('lines')->get()->keyBy(fn (PayRun $run) => $run->scope()->value);
    expect($runs)->toHaveCount(2)
        ->and($runs->pluck('status')->unique()->all())->toBe(['draft'])
        ->and($runs['crew']->pay_date->toDateString())->toBe('2026-11-22')
        ->and($runs['office']->lines->pluck('name')->all())->toBe(['Elena Bautista'])
        ->and($runs['crew']->lines->pluck('name')->all())->toBe(['Marco Villanueva'])
        ->and(PayrollDiscordAlert::count())->toBe(2);

    $this->getJson('/api/v1/assistant/payroll-alerts?channel_id='.$this->hrChannel)->assertForbidden();
    $token = $this->assistant->issueToken();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/assistant/payroll-alerts?channel_id=222222222222222222')->assertForbidden();
    $items = $this->getJson('/api/v1/assistant/payroll-alerts?channel_id='.$this->hrChannel)
        ->assertOk()->assertJsonCount(2, 'data')->json('data');
    $office = collect($items)->firstWhere('scope', 'office');
    expect($office['payments'][0])->toBe(['name' => 'Elena Bautista', 'net_cents' => $runs['office']->netCents()])
        ->and($office['total_cost_cents'])->toBe($runs['office']->grossCents() + $runs['office']->employerContributionsCents()['total']);
    $this->postJson("/api/v1/assistant/payroll-alerts/{$office['id']}/delivered", ['channel_id' => $this->hrChannel])
        ->assertNoContent();
    $this->getJson('/api/v1/assistant/payroll-alerts?channel_id='.$this->hrChannel)
        ->assertOk()->assertJsonCount(1, 'data');
});

it('validates date overrides and can clear them to use the normal delay', function (): void {
    $this->actingAs($this->admin)->patchJson('/api/v1/company', [
        'payroll_cutoff_days' => [15, 31],
        'payroll_pay_dates' => ['2026-11-15' => '2026-11-14'],
    ])->assertUnprocessable()->assertJsonValidationErrors('payroll_pay_dates');
    $this->patchJson('/api/v1/company', [
        'payroll_cutoff_days' => [15, 31], 'payroll_release_lag_days' => 2,
        'payroll_pay_dates' => ['2026-11-15' => '2026-11-20'],
    ])->assertOk();
    $this->patchJson('/api/v1/company', [
        'payroll_pay_dates' => ['2026-11-15' => null],
    ])->assertOk();
    expect($this->company->fresh()->payrollCalendar()->releaseFor(Carbon::parse('2026-11-15'))->toDateString())
        ->toBe('2026-11-17');
});
