<?php

declare(strict_types=1);

use App\Domain\Assistant\Services\AssistantService;
use App\Domain\Driver\Models\Driver;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessService;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function virtualEmployee(string $number, array $attributes = []): Employee
{
    return Employee::create([
        'employee_no' => $number,
        'first_name' => $number,
        'last_name' => 'Test',
        'position' => 'Specialist',
        'hired_on' => now()->toDateString(),
        'contact' => '123456',
        ...$attributes,
    ]);
}

it('shows the menu and permits reads for administrators and developers only', function (): void {
    $this->seed(PermissionSeeder::class);
    $developerRole = app(AccessService::class)->createRole([
        'name' => 'Developer',
        'permissions' => ['automation.manage'],
    ]);
    $admin = User::factory()->create();
    $developer = User::factory()->create(['role' => $developerRole->key]);
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);

    $this->getJson('/api/v1/virtual-operations')->assertUnauthorized();

    foreach ([$admin, $developer] as $user) {
        $this->actingAs($user)->getJson('/api/v1/navigation')->assertOk()
            ->assertJsonFragment(['key' => 'virtual-operations', 'label' => 'Virtual Operations', 'route' => '/virtual-operations']);
        $this->getJson('/api/v1/navigation?client=mobile')->assertOk()
            ->assertJsonMissing(['key' => 'virtual-operations']);
        $this->getJson('/api/v1/virtual-operations')->assertOk()
            ->assertJsonFragment(['id' => 'ai', 'name' => 'AI Agent Office']);
    }

    $this->putJson('/api/v1/virtual-operations/layout', [])->assertForbidden();
    $this->actingAs($dispatcher)->getJson('/api/v1/navigation')->assertOk()
        ->assertJsonMissing(['key' => 'virtual-operations']);
    $this->getJson('/api/v1/virtual-operations')->assertForbidden();
});

it('uses tenant staff, real crew assignments and stable appearances', function (): void {
    $admin = User::factory()->create();
    $driver = Driver::create(['name' => 'Driver', 'licence_no' => 'VOC-LICENCE', 'licence_expiry' => now()->addYear()]);
    $helper = Driver::create(['name' => 'Helper', 'role' => 'helper']);
    $vehicle = Vehicle::create(['plate' => 'VOC 123', 'model' => 'Isuzu', 'registration_no' => 'VOC-V1', 'capacity_kg' => 4000]);
    $driverPosition = Position::create(['key' => 'driver', 'name' => 'Linehaul Driver']);
    $driverEmployee = virtualEmployee('VOC-DRIVER', ['driver_id' => $driver->id, 'position_id' => $driverPosition->id]);
    $helperEmployee = virtualEmployee('VOC-HELPER', ['driver_id' => $helper->id, 'position' => 'Helper']);
    $officeEmployee = virtualEmployee('VOC-OFFICE');
    $inactive = virtualEmployee('VOC-INACTIVE', ['status' => 'inactive']);
    $foreign = $this->asCompany($this->makeCompany('Other Operations'), fn () => virtualEmployee('VOC-FOREIGN'));

    $trip = Trip::create([
        'origin' => 'Warehouse', 'destination' => 'Dock', 'cargo' => 'Boxes', 'weight_kg' => 20,
        'scheduled_at' => now(), 'status' => 'in_transit', 'driver_id' => $driver->id, 'vehicle_id' => $vehicle->id,
    ]);
    $trip->setHelpers([$helper->id]);

    $response = $this->actingAs($admin)->getJson('/api/v1/virtual-operations')->assertOk()
        ->assertJsonPath('data.dispatch.in_transit', 1);
    $employees = collect($response->json('data.employees'))->keyBy('id');
    expect($employees->keys()->all())->toBe([$driverEmployee->id, $helperEmployee->id, $officeEmployee->id])
        ->and($employees[$driverEmployee->id])->toMatchArray([
            'position' => 'Linehaul Driver', 'room' => 'drivers', 'work_status' => 'on_delivery',
            'vehicle' => $vehicle->plate, 'delivery' => $trip->reference,
        ])
        ->and($employees[$helperEmployee->id])->toMatchArray(['room' => 'helpers', 'work_status' => 'on_delivery'])
        ->and($employees[$officeEmployee->id])->toMatchArray(['room' => 'other', 'work_status' => 'unknown']);

    $this->getJson('/api/v1/virtual-operations')->assertOk()
        ->assertJsonPath('data.employees.0.appearance', $employees[$driverEmployee->id]['appearance']);

    $appearance = ['hair' => 3, 'skin' => 1, 'outfit' => 4, 'accessory' => 2];
    $this->putJson("/api/v1/virtual-operations/employees/{$driverEmployee->id}/appearance", $appearance)
        ->assertOk()->assertJsonPath('data', $appearance);
    $this->putJson("/api/v1/virtual-operations/employees/{$inactive->id}/appearance", $appearance)->assertNotFound();
    $this->putJson("/api/v1/virtual-operations/employees/{$foreign->id}/appearance", $appearance)->assertNotFound();
    $this->getJson('/api/v1/virtual-operations')->assertOk()->assertJsonPath('data.employees.0.appearance', $appearance);
});

it('persists custom areas and position rules and exposes the paired assistant heartbeat', function (): void {
    $admin = User::factory()->create();
    virtualEmployee('VOC-SPECIALIST');
    $assistant = app(AssistantService::class);
    $assistant->update(['enabled' => true]);
    $assistant->issueToken();
    $assistant->reportStatus(['state' => 'online', 'tag' => 'Private Discord tag']);

    $layout = [
        'rooms' => [['id' => 'custom-specialists', 'name' => 'Specialists Office']],
        'rules' => [['key' => 'title:specialist', 'room' => 'custom-specialists']],
    ];

    $this->actingAs($admin)->putJson('/api/v1/virtual-operations/layout', $layout)->assertOk()
        ->assertJsonPath('data.rooms.0.name', 'Specialists Office')
        ->assertJsonPath('data.employees.0.room', 'custom-specialists');

    $this->getJson('/api/v1/virtual-operations')->assertOk()
        ->assertJsonPath('data.employees.0.room', 'custom-specialists')
        ->assertJsonPath('data.agent.state', 'online')
        ->assertJsonMissingPath('data.agent.tag');

    $layout['rules'][0]['room'] = 'ai';
    $this->putJson('/api/v1/virtual-operations/layout', $layout)->assertUnprocessable();
});

it('shows each parked company truck and hides trucks with a started delivery', function (): void {
    $admin = User::factory()->create();
    $truck = fn (string $plate) => Vehicle::create([
        'plate' => $plate, 'model' => 'Isuzu', 'registration_no' => $plate, 'capacity_kg' => 4000,
    ]);
    $trip = fn (Vehicle $vehicle, string $status) => Trip::create([
        'origin' => 'Warehouse', 'destination' => 'Dock', 'cargo' => 'Boxes', 'weight_kg' => 20,
        'scheduled_at' => now(), 'status' => $status, 'vehicle_id' => $vehicle->id, 'price_cents' => 10000,
    ]);

    $assigned = $trip($truck('VOC-ASSIGNED'), 'assigned');
    $enRoute = $trip($truck('VOC-IN-TRANSIT'), 'in_transit');
    $trip($truck('VOC-LATE-UNSTARTED'), 'overdue');
    $lateStarted = $trip($truck('VOC-LATE-STARTED'), 'overdue');
    $lateStarted->dispatchRecord()->create([
        'status' => 'in_transit', 'dispatched_at' => now()->subHour(), 'location' => 'Warehouse',
    ]);
    $truck('VOC-SERVICE')->update(['status' => 'maintenance']);
    $truck('VOC-ACTIVE')->update(['status' => 'active']);
    $truck('VOC-ARCHIVED')->delete();
    $this->asCompany($this->makeCompany('Other Fleet'), fn () => $truck('VOC-OTHER-COMPANY'));

    $yard = fn () => $this->actingAs($admin)->getJson('/api/v1/virtual-operations')->assertOk()->json('data.yard');
    $initial = $yard();
    expect($initial['traveling'])->toBe(2)
        ->and(array_column($initial['parked'], 'plate'))->toBe(['VOC-ACTIVE', 'VOC-ASSIGNED', 'VOC-LATE-UNSTARTED', 'VOC-SERVICE'])
        ->and(array_column($initial['fleet'], 'fleet_status', 'plate'))->toBe([
            'VOC-ACTIVE' => 'active', 'VOC-ASSIGNED' => 'active', 'VOC-IN-TRANSIT' => 'traveling',
            'VOC-LATE-STARTED' => 'traveling', 'VOC-LATE-UNSTARTED' => 'active', 'VOC-SERVICE' => 'unavailable',
        ]);

    $enRoute->update(['status' => 'delivered']);
    $this->postJson("/api/v1/trips/{$assigned->id}/dispatch", ['location' => 'Warehouse'])
        ->assertOk()->assertJsonPath('data.status', 'in_transit');
    $updated = $yard();
    expect($updated['traveling'])->toBe(2)
        ->and(array_column($updated['parked'], 'plate'))->toBe(['VOC-ACTIVE', 'VOC-IN-TRANSIT', 'VOC-LATE-UNSTARTED', 'VOC-SERVICE'])
        ->and(collect($updated['fleet'])->firstWhere('plate', 'VOC-ASSIGNED')['fleet_status'])->toBe('traveling');
});

it('answers through the configured AI provider with scoped totals and no roster details', function (): void {
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'One truck is parked.']]]])]);
    $admin = User::factory()->create();
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $question = ['agent' => 'fleet', 'question' => 'How many trucks are parked?'];

    $this->actingAs($dispatcher)->postJson('/api/v1/virtual-operations/ask', $question)->assertForbidden();
    $this->actingAs($admin)->postJson('/api/v1/virtual-operations/ask', $question)->assertUnprocessable();
    Http::assertNothingSent();

    virtualEmployee('VOC-PRIVATE-NAME');
    Vehicle::create(['plate' => 'VOC-PRIVATE-PLATE', 'model' => 'Isuzu', 'registration_no' => 'VOC-P1', 'capacity_kg' => 4000]);
    app(AssistantService::class)->update(['enabled' => true, 'llm_provider' => 'openai', 'api_key' => 'test-token']);

    $this->getJson('/api/v1/virtual-operations')->assertOk()->assertJsonPath('data.agent.chat_ready', true);
    $this->postJson('/api/v1/virtual-operations/ask', $question)->assertOk()->assertJsonPath('data.answer', 'One truck is parked.');
    Http::assertSent(fn (Request $request) => json_decode($request['messages'][1]['content'], true) === [
        'snapshot' => ['fleet' => ['parked' => 1, 'traveling' => 0, 'active_in_yard' => 0, 'available' => 1, 'unavailable' => 0]],
        'question' => $question['question'],
    ]);
});
