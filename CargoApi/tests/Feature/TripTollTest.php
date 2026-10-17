<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Models\Expense;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\SubsidyRateCardSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(NavigationSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();
    $this->customer = Customer::firstOrFail();

    $this->trip = fn (array $overrides = []) => $this->actingAs($this->admin)->postJson('/api/v1/trips', [
        'origin' => 'Cagayan de Oro',
        'destination' => 'Iligan',
        'cargo' => 'Assorted retail',
        'weight_kg' => 2400,
        'price_cents' => 500_000,
        'customer_id' => $this->customer->id,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'scheduled_at' => '2026-06-10 08:00:00',
        'delivered_at' => '2026-06-10 15:30:00',
        'status' => StatusValue::Delivered->value,
        ...$overrides,
    ]);

    $this->tolls = fn () => collect(
        $this->actingAs($this->admin)
            ->getJson('/api/v1/finance/expense-lines?from=2026-06-01&to=2026-06-30')
            ->assertOk()
            ->json('data.lines'),
    )->where('kind', 'Toll & Parking')->values();

    $this->tollAccount = fn (): int => (int) JournalLine::query()
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
        ->where('journal_entries.status', JournalEntry::POSTED)
        ->where('journal_lines.account_id', Account::where('code', '5070')->value('id'))
        ->sum(DB::raw(DB::getQueryGrammar()->wrap('journal_lines.debit_cents').' - '.DB::getQueryGrammar()->wrap('journal_lines.credit_cents')));
});

it('takes a toll on a trip, and leaves it out when none is given', function (): void {
    ($this->trip)(['toll_cents' => 35_000])->assertCreated()->assertJsonPath('data.toll_cents', 35_000);
    ($this->trip)()->assertCreated()->assertJsonPath('data.toll_cents', null);
});

it('refuses a negative toll', function (): void {
    ($this->trip)(['toll_cents' => -1])->assertUnprocessable()->assertJsonValidationErrors('toll_cents');
});

it('puts a delivered trip\'s toll on the expenses, the books and the summary', function (): void {
    $before = $this->actingAs($this->admin)
        ->getJson('/api/v1/finance/summary?year=2026&quarter=q2')
        ->json('data.totals.total_expenses_cents');

    $id = ($this->trip)(['toll_cents' => 35_000])->assertCreated()->json('data.id');

    $expense = Expense::where('trip_id', $id)->sole();

    expect($expense->amount_cents)->toBe(35_000)
        ->and($expense->date->toDateString())->toBe('2026-06-10')
        ->and($expense->truck_id)->not->toBeNull()
        ->and(($this->tolls)()->sum('amount_cents'))->toBe(35_000)
        ->and(($this->tollAccount)())->toBe(35_000)
        ->and($this->actingAs($this->admin)
            ->getJson('/api/v1/finance/summary?year=2026&quarter=q2')
            ->json('data.totals.total_expenses_cents'))->toBe($before + 35_000);
});

it('follows a change to the toll, without counting it twice', function (): void {
    $id = ($this->trip)(['toll_cents' => 35_000])->json('data.id');

    $this->actingAs($this->admin)->patchJson("/api/v1/trips/{$id}", ['toll_cents' => 50_000])->assertOk();

    expect(($this->tolls)()->pluck('amount_cents')->all())->toBe([50_000])
        ->and(($this->tollAccount)())->toBe(50_000);

    $this->actingAs($this->admin)->patchJson("/api/v1/trips/{$id}", ['toll_cents' => null])->assertOk();

    expect(($this->tolls)())->toHaveCount(0)
        ->and(($this->tollAccount)())->toBe(0);
});

it('takes the toll back off when the trip is cancelled', function (): void {
    $id = ($this->trip)(['toll_cents' => 35_000])->json('data.id');

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/trips/{$id}", ['status' => StatusValue::Cancelled->value])
        ->assertOk();

    expect(Expense::where('trip_id', $id)->count())->toBe(0)
        ->and(DeliveryLog::where('trip_id', $id)->value('posted_toll_expense_id'))->toBeNull()
        ->and(($this->tollAccount)())->toBe(0);
});

it('does not post a toll until the trip is delivered', function (): void {
    $id = ($this->trip)([
        'toll_cents' => 35_000,
        'status' => StatusValue::Pending->value,
        'delivered_at' => null,
    ])->assertCreated()->json('data.id');

    expect(Expense::where('trip_id', $id)->count())->toBe(0);
});

it('shows the toll on the trip ticket', function (): void {
    $id = ($this->trip)(['toll_cents' => 35_000])->json('data.id');

    $this->actingAs($this->admin)->getJson("/api/v1/trips/{$id}/ticket")
        ->assertOk()
        ->assertJsonPath('data.toll_cents', 35_000);
});

it('lists trips by reference, newest first, whatever was edited last', function (): void {
    $first = ($this->trip)(['scheduled_at' => '2026-06-20 08:00:00', 'delivered_at' => '2026-06-20 15:00:00'])->json('data');
    $second = ($this->trip)(['scheduled_at' => '2026-06-01 08:00:00', 'delivered_at' => '2026-06-01 15:00:00'])->json('data');

    $demo = ($this->trip)()->json('data');
    Trip::whereKey($demo['id'])->update(['reference' => 'DEMO-TR-D07', 'reference_seq' => null]);

    $this->actingAs($this->admin)->patchJson("/api/v1/trips/{$first['id']}", ['cargo' => 'Rice'])->assertOk();

    $references = collect($this->actingAs($this->admin)->getJson('/api/v1/trips')->assertOk()->json('data'))
        ->pluck('reference');

    expect($references->first())->toBe($second['reference'])
        ->and($references->search($second['reference']))->toBeLessThan($references->search($first['reference']))
        ->and($references->search('DEMO-TR-D07'))->toBeGreaterThan($references->search($first['reference']));
});


it('numbers trips CTS-date-series, running on without resetting', function (): void {
    $this->travelTo(Carbon\Carbon::parse('2026-10-17 01:00:00', 'Asia/Manila'));
    $first = ($this->trip)()->json('data.reference');

    $this->travelTo(Carbon\Carbon::parse('2026-10-18 09:00:00', 'Asia/Manila'));
    $second = ($this->trip)()->json('data.reference');

    $series = fn (string $ref): int => (int) substr($ref, -5);

    expect($first)->toMatch('/^CTS-101726-\d{5}$/')
        ->and($second)->toMatch('/^CTS-101826-\d{5}$/')
        ->and($series($second))->toBe($series($first) + 1);
});

it('renumbers existing trips in the order they were made, and still finds them by the old number', function (): void {
    $older = ($this->trip)()->json('data.id');
    $newer = ($this->trip)()->json('data.id');

    Trip::whereKey($older)->update(['reference' => 'CR-24900', 'reference_seq' => null, 'created_at' => '2026-03-05 02:00:00']);
    Trip::whereKey($newer)->update(['reference' => 'CR-24901', 'reference_seq' => null, 'created_at' => '2026-03-06 20:00:00']);

    $migration = require database_path('migrations/2026_10_17_000300_number_trips_as_cts.php');
    $migration->down();
    $migration->up();

    $older = Trip::findOrFail($older);
    $newer = Trip::findOrFail($newer);

    expect($older->reference)->toEndWith('-'.str_pad((string) $older->reference_seq, 5, '0', STR_PAD_LEFT))
        ->and($older->reference)->toStartWith('CTS-030526-')
        ->and($newer->reference)->toStartWith('CTS-030726-')
        ->and($newer->reference_seq)->toBe($older->reference_seq + 1)
        ->and($older->legacy_reference)->toBe('CR-24900')
        ->and(app(App\Domain\Trip\Repositories\TripRepository::class)->findByReference('CR-24901')?->id)->toBe($newer->id);
});

it('prices from the zone the typed kilometers fall in', function (): void {
    $this->seed(SubsidyRateCardSeeder::class);

    $zoneFor = fn (int $km): PricingZone => PricingZone::query()
        ->where('min_km', '<=', $km)
        ->where(fn ($q) => $q->whereNull('max_km')->orWhere('max_km', '>=', $km))
        ->orderBy('min_km')
        ->firstOrFail();

    $id = ($this->trip)(['status' => StatusValue::Scheduled->value, 'delivered_at' => null, 'price_cents' => null, 'distance_total_m' => 120_000])
        ->assertCreated()
        ->assertJsonPath('data.distance_source', 'manual')
        ->json('data.id');

    $trip = Trip::findOrFail($id);

    expect($trip->pricing_zone_id)->toBe($zoneFor(120)->id)
        ->and($trip->price_cents)->toBeGreaterThan(0);

    $this->actingAs($this->admin)->patchJson("/api/v1/trips/{$id}", ['distance_total_m' => 30_000])->assertOk();

    $row = collect($this->actingAs($this->admin)->getJson('/api/v1/trips')->assertOk()->json('data'))->firstWhere('id', $id);

    expect(Trip::findOrFail($id)->pricing_zone_id)->toBe($zoneFor(30)->id)
        ->and($row['distance_total_m'])->toBe(30_000)
        ->and($row['pricing_zone'])->toBe($zoneFor(30)->name);
});
