<?php

declare(strict_types=1);

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Trip\Services\TripTicketService;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\Demo\FleetSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;

/**
 * The printed paperwork a truck leaves with — the Official Trip Ticket and the
 * dispatch checklist — filled in from the trip.
 */
beforeEach(function (): void {
    zoneCard();

    $this->seed(NavigationSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(FleetSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::where('email', 'admin@cargorush.ph')->firstOrFail();
    $this->driver = Driver::where('name', 'Marco Reyes')->firstOrFail();
    $this->vehicle = Vehicle::where('plate', 'NCR 4412')->firstOrFail();

    $this->helper = fn (string $name, string $licence) => Driver::create([
        'name' => $name, 'licence_no' => $licence, 'licence_expiry' => '2029-08-31', 'status' => 'available',
    ]);

    $this->trip = fn (array $overrides = []) => $this->actingAs($this->admin)->postJson('/api/v1/trips', [
        'origin' => 'CDI Cagayan',
        'destination' => 'Dologon, Maramag',
        'cargo' => 'Convenience goods, 120 cartons',
        'weight_kg' => 3200,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'scheduled_at' => '2026-09-11T06:00:00+08:00',
        'status' => StatusValue::Assigned->value,
        ...$overrides,
    ])->assertCreated()->json('data');
});

it('fills the ticket in from the trip, the crew and the truck', function (): void {
    $jeffrey = ($this->helper)('Jeffrey Marba', 'H01-00-000001');
    $john = ($this->helper)('John Paul Trasadas', 'H01-00-000002');

    $trip = ($this->trip)(['helper_ids' => [$john->id, $jeffrey->id]]);

    $ticket = $this->actingAs($this->admin)->getJson("/api/v1/trips/{$trip['id']}/ticket")
        ->assertOk()
        ->json('data');

    expect($ticket['trip']['reference'])->toBe($trip['reference'])
        ->and($ticket['trip']['dispatch_date'])->toBe('2026-09-11')
        ->and($ticket['trip']['cargo'])->toBe('Convenience goods, 120 cartons')
        ->and($ticket['crew']['driver'])->toBe('Marco Reyes')
        ->and($ticket['crew']['licence_no'])->toBe($this->driver->licence_no)
        // In the order they were named.
        ->and($ticket['crew']['helper_1'])->toBe('John Paul Trasadas')
        ->and($ticket['crew']['helper_2'])->toBe('Jeffrey Marba')
        ->and($ticket['vehicle']['plate'])->toBe('NCR 4412')
        ->and($ticket['vehicle']['model'])->toBe($this->vehicle->model)
        ->and($ticket['issuer'])->toHaveKeys(['name', 'address', 'contact_email', 'logo_url']);
});

it('carries the dispatch checklist the paper form has', function (): void {
    $trip = ($this->trip)();

    $checklist = $this->actingAs($this->admin)->getJson("/api/v1/trips/{$trip['id']}/ticket")->json('data.checklist');

    expect(array_column($checklist, 'section'))->toBe(array_keys(TripTicketService::CHECKLIST))
        ->and($checklist[0]['items'][0])->toBe([
            'key' => 'licence',
            'label' => "Valid driver's license with appropriate classification",
            'answer' => null,
        ]);
});

it('leaves the helper boxes empty on a run with no helpers', function (): void {
    $trip = ($this->trip)();

    $crew = $this->actingAs($this->admin)->getJson("/api/v1/trips/{$trip['id']}/ticket")->json('data.crew');

    expect($crew['helper_1'])->toBeNull()->and($crew['helper_2'])->toBeNull()->and($crew['more_helpers'])->toBe([]);
});

it('needs trips.view', function (): void {
    $trip = ($this->trip)();

    // A trucker has no reason to read the fleet's dispatch paperwork.
    $trucker = User::create([
        'name' => 'Partner', 'email' => 'partner@test.test',
        'password' => 'password', 'role' => Role::Trucker->value,
    ]);

    $this->actingAs($trucker)->getJson("/api/v1/trips/{$trip['id']}/ticket")->assertForbidden();
});

describe('the dispatch checklist, answered on the phone', function (): void {
    beforeEach(function (): void {
        $this->marco = User::where('email', 'marco@cargorush.ph')->firstOrFail();

        /** Every line answered `$answer`, with any overrides. */
        $this->sheet = fn (string $answer = 'yes', array $overrides = []) => [
            ...array_fill_keys(TripTicketService::keys(), $answer),
            ...$overrides,
        ];
    });

    it('gives the phone the lines to ask', function (): void {
        $sections = $this->actingAs($this->marco)->getJson('/api/v1/trips/dispatch-checklist')
            ->assertOk()
            ->json('data');

        expect($sections)->toHaveCount(4)
            ->and($sections[0]['items'][0])->toBe([
                'key' => 'licence',
                'label' => "Valid driver's license with appropriate classification",
            ]);
    });

    it('prints the ticks the driver gave', function (): void {
        $trip = ($this->trip)();

        $this->actingAs($this->marco)
            ->postJson("/api/v1/trips/{$trip['id']}/dispatch-checklist", [
                'answers' => ($this->sheet)('yes', ['tire_chocks' => 'no', 'uniform' => 'na']),
                'remarks' => 'No chocks on board; borrowed at the depot.',
            ])
            ->assertOk()
            ->assertJsonPath('data.dispatch_checklist.checked_by', 'Marco Reyes')
            ->assertJsonPath('data.dispatch_checklist.answers.tire_chocks', 'no');

        $ticket = $this->actingAs($this->admin)->getJson("/api/v1/trips/{$trip['id']}/ticket")->json('data');
        $answers = collect($ticket['checklist'])->flatMap(fn ($s) => $s['items'])->pluck('answer', 'key');

        expect($answers['licence'])->toBe('yes')
            ->and($answers['tire_chocks'])->toBe('no')
            ->and($answers['uniform'])->toBe('na')
            ->and($ticket['checked']['by'])->toBe('Marco Reyes')
            ->and($ticket['checked']['remarks'])->toBe('No chocks on board; borrowed at the depot.')
            ->and($ticket['checked']['at'])->not->toBeNull();
    });

    it('replaces the answers when the sheet is answered again', function (): void {
        $trip = ($this->trip)();
        $url = "/api/v1/trips/{$trip['id']}/dispatch-checklist";

        $this->actingAs($this->marco)->postJson($url, ['answers' => ($this->sheet)('no')])->assertOk();
        $this->actingAs($this->marco)->postJson($url, ['answers' => ($this->sheet)('yes')])->assertOk()
            ->assertJsonPath('data.dispatch_checklist.answers.fuel', 'yes');
    });

    it('wants every line answered, with yes, no or n/a', function (): void {
        $trip = ($this->trip)();
        $url = "/api/v1/trips/{$trip['id']}/dispatch-checklist";

        $partial = ($this->sheet)();
        unset($partial['briefing']);

        $this->actingAs($this->marco)->postJson($url, ['answers' => $partial])
            ->assertUnprocessable()->assertJsonValidationErrors('answers');

        $this->actingAs($this->marco)->postJson($url, ['answers' => ($this->sheet)('yes', ['fuel' => 'maybe'])])
            ->assertUnprocessable()->assertJsonValidationErrors('answers.fuel');
    });

    it('lets a driver answer only their own run', function (): void {
        $other = Driver::create([
            'name' => 'Ana Lim', 'licence_no' => 'H01-00-000009', 'licence_expiry' => '2029-08-31', 'status' => 'available',
        ]);
        $trip = ($this->trip)(['driver_id' => $other->id]);

        $this->actingAs($this->marco)
            ->postJson("/api/v1/trips/{$trip['id']}/dispatch-checklist", ['answers' => ($this->sheet)()])
            ->assertForbidden();
    });

    it('prints empty boxes when nobody has answered', function (): void {
        $trip = ($this->trip)();

        $ticket = $this->actingAs($this->admin)->getJson("/api/v1/trips/{$trip['id']}/ticket")->json('data');

        expect(collect($ticket['checklist'])->flatMap(fn ($s) => $s['items'])->pluck('answer')->unique()->all())->toBe([null])
            ->and($ticket['checked']['at'])->toBeNull();
    });
});
