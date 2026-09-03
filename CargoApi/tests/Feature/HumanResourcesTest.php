<?php

declare(strict_types=1);

use App\Domain\Driver\Models\Driver;
use App\Domain\Hr\Models\Applicant;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * People — the roster, the pipeline, and who sees which module.
 *
 * The behaviour worth pinning is the boundary between the three records this
 * system keeps for a person. An employee is the HR record; `drivers` is still
 * the operational history every trip points at; `users` is still the login. HR
 * links them and replaces neither, and the tests below are what stops a later
 * change from quietly collapsing the three into one.
 *
 * And the module assignment, which is the one piece of this that is a security
 * question rather than a display one: it narrows what a role allows and can
 * never widen it.
 */
beforeEach(function (): void {
    Storage::fake('public');

    // Roles are rows now, so an account cannot be given one the table does not
    // have. A fresh install seeds these; a test that issues logins needs them.
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(NavigationSeeder::class);

    $this->admin = User::factory()->create(['role' => Role::Administrator]);

    $this->form = [
        'first_name' => 'Marco',
        'last_name' => 'Reyes',
        'position' => 'Driver',
        'department' => 'Operations',
        'contact' => '0917 555 0101',
        'hired_on' => '2026-03-01',
    ];

    $this->register = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/employees', [...$this->form, ...$overrides]);

    $this->apply = fn (array $overrides = []) => $this->actingAs($this->admin)
        ->postJson('/api/v1/applicants', [
            'first_name' => 'Jun',
            'last_name' => 'Abad',
            'position_applied' => 'Helper',
            'contact' => '0918 555 0202',
            ...$overrides,
        ]);
});

describe('registering an employee', function (): void {
    it('allocates a payroll number when the office has none to give', function (): void {
        $response = ($this->register)()->assertCreated();

        expect($response->json('data.employee_no'))->toBe('EMP-0001');
        expect($response->json('data.full_name'))->toBe('Marco Reyes');
        expect($response->json('data.has_account'))->toBeFalse();
    });

    it('does not reissue a number that has been on a payslip', function (): void {
        $first = ($this->register)()->json('data.id');
        Employee::findOrFail($first)->delete();

        $second = ($this->register)(['first_name' => 'Ana'])->assertCreated();

        expect($second->json('data.employee_no'))->not->toBe('EMP-0001');
    });

    it('keeps the photograph and hands back a URL to read it with', function (): void {
        $response = $this->actingAs($this->admin)->post('/api/v1/employees', [
            ...$this->form,
            'photo' => UploadedFile::fake()->create('marco.jpg', 120, 'image/jpeg'),
        ])->assertCreated();

        expect($response->json('data.photo_url'))->not->toBeNull();
        expect(Storage::disk('public')->allFiles('people/employees'))->toHaveCount(1);
    });

    it('leaves the photograph alone on an edit that does not send one', function (): void {
        $id = $this->actingAs($this->admin)->post('/api/v1/employees', [
            ...$this->form,
            'photo' => UploadedFile::fake()->create('marco.jpg', 120, 'image/jpeg'),
        ])->json('data.id');

        $before = Employee::findOrFail($id)->photo_path;

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/$id", ['position' => 'Senior Driver'])
            ->assertOk();

        expect(Employee::findOrFail($id)->photo_path)->toBe($before);
    });

    it('links to a driver record without replacing it', function (): void {
        $driver = Driver::create([
            'name' => 'Marco Reyes',
            'licence_no' => 'N01-23-456789',
            'licence_expiry' => '2029-01-01',
        ]);

        $response = ($this->register)(['driver_id' => $driver->id])->assertCreated();

        expect($response->json('data.driver_name'))->toBe('Marco Reyes');
        // The operational record is untouched: every trip in the system points
        // at it, and HR arriving must not move that ground.
        expect($driver->refresh()->exists)->toBeTrue();
    });

    it('refuses to link one driver to two employees', function (): void {
        $driver = Driver::create([
            'name' => 'Marco Reyes',
            'licence_no' => 'N01-23-456789',
            'licence_expiry' => '2029-01-01',
        ]);

        ($this->register)(['driver_id' => $driver->id])->assertCreated();

        ($this->register)(['first_name' => 'Someone', 'driver_id' => $driver->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('driver_id');
    });

    it('reports headcount and who still has no login', function (): void {
        ($this->register)();
        ($this->register)(['first_name' => 'Ana', 'position' => 'Dispatcher']);

        $overview = $this->actingAs($this->admin)
            ->getJson('/api/v1/employees/overview')
            ->assertOk();

        expect($overview->json('data.headcount'))->toBe(2);
        expect($overview->json('data.without_account'))->toBe(2);
        expect($overview->json('data.by_position'))->toHaveCount(2);
    });
});

describe('giving an employee a login', function (): void {
    beforeEach(function (): void {
        $this->employee = Employee::findOrFail(($this->register)()->json('data.id'));

        $this->giveAccount = fn (array $overrides = []) => $this->actingAs($this->admin)
            ->postJson("/api/v1/employees/{$this->employee->id}/account", [
                'email' => 'marco@cargorush.ph',
                'role' => Role::Driver->value,
                ...$overrides,
            ]);
    });

    it('creates the account and returns the credentials once', function (): void {
        $response = ($this->giveAccount)()->assertCreated();

        expect($response->json('data.has_account'))->toBeTrue();
        expect($response->json('data.role'))->toBe('driver');
        expect($response->json('meta.credentials.email'))->toBe('marco@cargorush.ph');
        expect($response->json('meta.credentials.password'))->toBe('cargorush123');
    });

    it('lets the new account sign in', function (): void {
        ($this->giveAccount)();

        $this->postJson('/api/v1/login', [
            'email' => 'marco@cargorush.ph',
            'password' => 'cargorush123',
            'device_name' => 'pixel-8',
        ])->assertCreated();
    });

    it('refuses an address that already belongs to somebody', function (): void {
        ($this->giveAccount)(['email' => $this->admin->email])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    });

    it('refuses a second account for the same employee', function (): void {
        ($this->giveAccount)()->assertCreated();

        ($this->giveAccount)(['email' => 'marco2@cargorush.ph'])->assertStatus(422);
    });

    it('will not make a member of staff a customer account', function (): void {
        ($this->giveAccount)(['role' => Role::Customer->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    });

    it('gives the linked driver the same login, so the handset can reach them', function (): void {
        $driver = Driver::create([
            'name' => 'Marco Reyes',
            'licence_no' => 'N01-23-456789',
            'licence_expiry' => '2029-01-01',
        ]);

        $this->employee->update(['driver_id' => $driver->id]);

        ($this->giveAccount)()->assertCreated();

        expect($driver->refresh()->user_id)->toBe($this->employee->refresh()->user_id);
    });

    it('carries a name correction through to the account', function (): void {
        ($this->giveAccount)();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$this->employee->id}", ['last_name' => 'Reyes-Cruz'])
            ->assertOk();

        expect($this->employee->refresh()->user->name)->toBe('Marco Reyes-Cruz');
    });
});

describe('role and module assignment', function (): void {
    beforeEach(function (): void {
        $this->employee = Employee::findOrFail(($this->register)()->json('data.id'));

        $this->actingAs($this->admin)->postJson("/api/v1/employees/{$this->employee->id}/account", [
            'email' => 'ana@cargorush.ph',
            'role' => Role::Dispatcher->value,
        ])->assertCreated();

        $this->employee->refresh();
    });

    it('lists what the role allows and says the menu is not customised yet', function (): void {
        $state = $this->actingAs($this->admin)
            ->getJson("/api/v1/employees/{$this->employee->id}/modules")
            ->assertOk();

        expect($state->json('data.role'))->toBe('dispatcher');
        expect($state->json('data.customised'))->toBeFalse();

        $keys = collect($state->json('data.available'))->pluck('key');
        expect($keys)->toContain('trips');
        // A dispatcher holds no finance permission, so the rate card is not
        // among the modules that could even be offered to them.
        expect($keys)->not->toContain('pricing');
    });

    it('narrows the sidebar to the modules assigned', function (): void {
        $this->actingAs($this->admin)
            ->putJson("/api/v1/employees/{$this->employee->id}/modules", [
                'modules' => ['dashboard', 'trips'],
            ])
            ->assertOk();

        $nav = $this->actingAs($this->employee->user)
            ->getJson('/api/v1/navigation')
            ->assertOk();

        expect(collect($nav->json('data'))->pluck('key')->all())->toBe(['dashboard', 'trips']);
    });

    it('cannot widen past what the role allows', function (): void {
        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/employees/{$this->employee->id}/modules", [
                'modules' => ['dashboard', 'pricing', 'employees'],
            ])
            ->assertOk();

        expect($response->json('data.assigned'))->toBe(['dashboard']);
        // Named back rather than silently dropped, so the UI can say why the
        // box would not stick.
        expect($response->json('meta.rejected'))->toEqualCanonicalizing(['pricing', 'employees']);

        $nav = $this->actingAs($this->employee->user)->getJson('/api/v1/navigation');

        expect(collect($nav->json('data'))->pluck('key')->all())->toBe(['dashboard']);
    });

    it('restores the full role menu when the assignment is cleared', function (): void {
        $this->actingAs($this->admin)->putJson("/api/v1/employees/{$this->employee->id}/modules", [
            'modules' => ['dashboard'],
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/employees/{$this->employee->id}/modules", ['modules' => []])
            ->assertOk();

        expect($response->json('data.customised'))->toBeFalse();

        $nav = $this->actingAs($this->employee->user)->getJson('/api/v1/navigation');

        expect(count($nav->json('data')))->toBeGreaterThan(1);
    });

    it('clears a stale assignment when the role changes', function (): void {
        $this->actingAs($this->admin)->putJson("/api/v1/employees/{$this->employee->id}/modules", [
            'modules' => ['dashboard', 'trips'],
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/employees/{$this->employee->id}/role", ['role' => Role::Accountant->value])
            ->assertOk();

        expect(DB::table('user_modules')->where('user_id', $this->employee->user_id)->count())->toBe(0);

        $nav = $this->actingAs($this->employee->user->refresh())->getJson('/api/v1/navigation');

        // The promotion actually took: the accountant's modules are there.
        expect(collect($nav->json('data'))->pluck('key'))->toContain('pricing');
    });

    it('refuses to assign modules to an employee with no account', function (): void {
        $other = Employee::findOrFail(($this->register)(['first_name' => 'Nobody'])->json('data.id'));

        $this->actingAs($this->admin)
            ->putJson("/api/v1/employees/{$other->id}/modules", ['modules' => ['dashboard']])
            ->assertStatus(422);
    });
});

describe('the applicant pipeline', function (): void {
    it('takes an application, defaulting the date to today', function (): void {
        $response = ($this->apply)()->assertCreated();

        expect($response->json('data.stage'))->toBe('applied');
        expect($response->json('data.applied_on'))->toBe(now()->toDateString());
        expect($response->json('data.open'))->toBeTrue();
    });

    it('keeps the CV and hands back a URL', function (): void {
        $response = $this->actingAs($this->admin)->post('/api/v1/applicants', [
            'first_name' => 'Jun',
            'last_name' => 'Abad',
            'position_applied' => 'Helper',
            'contact' => '0918 555 0202',
            'resume' => UploadedFile::fake()->create('jun-abad-cv.pdf', 200, 'application/pdf'),
        ])->assertCreated();

        expect($response->json('data.resume_url'))->not->toBeNull();
    });

    it('stamps the decision date when a stage moves', function (): void {
        $id = ($this->apply)()->json('data.id');

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/applicants/$id/stage", ['stage' => 'interview'])
            ->assertOk();

        expect($response->json('data.stage'))->toBe('interview');
        expect($response->json('data.stage_label'))->toBe('Interview');
        expect($response->json('data.decided_at'))->not->toBeNull();
    });

    it('refuses to reach hired through a stage change', function (): void {
        $id = ($this->apply)()->json('data.id');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/applicants/$id/stage", ['stage' => 'hired'])
            ->assertStatus(422);
    });

    it('shows every stage in the pipeline, including the empty ones', function (): void {
        ($this->apply)();

        $pipeline = $this->actingAs($this->admin)
            ->getJson('/api/v1/applicants/pipeline')
            ->assertOk();

        expect($pipeline->json('data.stages'))->toHaveCount(6);
        expect($pipeline->json('data.open'))->toBe(1);
    });

    it('counts only the open applications on the nav badge', function (): void {
        ($this->apply)();
        $rejected = ($this->apply)(['first_name' => 'Someone'])->json('data.id');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/applicants/$rejected/stage", ['stage' => 'rejected']);

        $nav = $this->actingAs($this->admin)->getJson('/api/v1/navigation');
        $row = collect($nav->json('data'))->firstWhere('key', 'applicants');

        expect($row['badge'])->toBe(1);
    });
});

describe('hiring an applicant', function (): void {
    it('builds the employee record from the application', function (): void {
        $id = ($this->apply)(['email' => 'jun@example.ph', 'address' => 'Toril, Davao City'])
            ->json('data.id');

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/applicants/$id/hire", ['base_salary_cents' => 1_800_000])
            ->assertCreated();

        expect($response->json('data.full_name'))->toBe('Jun Abad');
        expect($response->json('data.position'))->toBe('Helper');
        expect($response->json('data.email'))->toBe('jun@example.ph');
        expect($response->json('data.base_salary_cents'))->toBe(1_800_000);
        expect($response->json('data.employee_no'))->toBe('EMP-0001');
    });

    it('closes the application and points it at the new employee', function (): void {
        $id = ($this->apply)()->json('data.id');

        $employeeId = $this->actingAs($this->admin)
            ->postJson("/api/v1/applicants/$id/hire")
            ->json('data.id');

        $applicant = Applicant::findOrFail($id);

        expect($applicant->stage->value)->toBe('hired');
        expect($applicant->employee_id)->toBe($employeeId);
        expect($applicant->decided_at)->not->toBeNull();
    });

    it('carries the photograph across without duplicating the file', function (): void {
        $id = $this->actingAs($this->admin)->post('/api/v1/applicants', [
            'first_name' => 'Jun',
            'last_name' => 'Abad',
            'position_applied' => 'Helper',
            'contact' => '0918 555 0202',
            'photo' => UploadedFile::fake()->create('jun.jpg', 120, 'image/jpeg'),
        ])->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/applicants/$id/hire")->assertCreated();

        expect(Storage::disk('public')->allFiles('people'))->toHaveCount(1);
        expect(Employee::first()->photo_path)->toBe(Applicant::findOrFail($id)->photo_path);
    });

    it('refuses to hire the same person twice', function (): void {
        $id = ($this->apply)()->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/applicants/$id/hire")->assertCreated();
        $this->actingAs($this->admin)->postJson("/api/v1/applicants/$id/hire")->assertStatus(422);

        expect(Employee::count())->toBe(1);
    });
});

describe('editing with a new upload', function (): void {
    /**
     * The client cannot send a photograph on a PATCH.
     *
     * PHP does not populate `$_FILES` on a PUT or a PATCH, so the web client
     * posts an edit with `_method=PATCH` in the body — Laravel's own method
     * override. This pins that the route actually accepts it: without the
     * override the request reaches `POST /employees/{id}`, which no route
     * defines, and every photograph change would 405.
     */
    it('accepts a multipart edit posted with _method=PATCH', function (): void {
        $id = ($this->register)()->json('data.id');
        $before = Employee::findOrFail($id)->photo_path;

        $response = $this->actingAs($this->admin)->post("/api/v1/employees/$id", [
            '_method' => 'PATCH',
            'position' => 'Senior Driver',
            'photo' => UploadedFile::fake()->create('new.jpg', 120, 'image/jpeg'),
        ])->assertOk();

        expect($response->json('data.position'))->toBe('Senior Driver');
        expect($response->json('data.photo_url'))->not->toBeNull();
        expect(Employee::findOrFail($id)->photo_path)->not->toBe($before);
    });

    it('replaces the old photograph rather than leaving it orphaned on disk', function (): void {
        $id = $this->actingAs($this->admin)->post('/api/v1/employees', [
            ...$this->form,
            'photo' => UploadedFile::fake()->create('first.jpg', 120, 'image/jpeg'),
        ])->json('data.id');

        $this->actingAs($this->admin)->post("/api/v1/employees/$id", [
            '_method' => 'PATCH',
            'photo' => UploadedFile::fake()->create('second.jpg', 120, 'image/jpeg'),
        ])->assertOk();

        // One file, not two. A photograph is re-taken whenever somebody does
        // not like theirs, and without the replace every attempt would stay on
        // disk forever with nothing pointing at it.
        expect(Storage::disk('public')->allFiles('people/employees'))->toHaveCount(1);
    });

    it('accepts a multipart edit of an applicant the same way', function (): void {
        $id = ($this->apply)()->json('data.id');

        $response = $this->actingAs($this->admin)->post("/api/v1/applicants/$id", [
            '_method' => 'PATCH',
            'rating' => 4,
            'resume' => UploadedFile::fake()->create('cv.pdf', 120, 'application/pdf'),
        ])->assertOk();

        expect($response->json('data.rating'))->toBe(4);
        expect($response->json('data.resume_url'))->not->toBeNull();
    });
});
