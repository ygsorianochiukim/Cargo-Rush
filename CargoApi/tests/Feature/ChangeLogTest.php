<?php

declare(strict_types=1);

use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Support\ChangeLog;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PositionSeeder::class);
    $this->seed(NavigationSeeder::class);

    $this->admin = User::factory()->create(['role' => Role::Administrator]);

    $this->register = fn () => $this->actingAs($this->admin)->postJson('/api/v1/employees', [
        'first_name' => 'Marco',
        'last_name' => 'Reyes',
        'position' => 'Driver',
        'contact' => '0917 555 0101',
        'hired_on' => '2026-03-01',
    ])->assertCreated()->json('data');

    $this->physicalTables = fn (): array => array_map(
        static fn (object $row): string => $row->name,
        DB::select("select name from sqlite_master where type = 'table'"),
    );

    $this->logsFor = fn (string $table, string $id) => DB::table(ChangeLog::logTable($table))
        ->where('record_id', $id)
        ->orderBy('id')
        ->get();
});

describe('the fms_ prefix', function (): void {
    it('stores every table under the prefix', function (): void {
        $tables = ($this->physicalTables)();

        expect(DB::getTablePrefix())->toBe('fms_')
            ->and($tables)->toContain('fms_employees', 'fms_users', 'fms_migrations', 'fms_employees_logs')
            ->and($tables)->not->toContain('employees');
    });

    it('gives every logged table its own log table', function (): void {
        foreach (ChangeLog::TABLES as $table) {
            expect(Schema::hasTable(ChangeLog::logTable($table)))->toBeTrue("{$table} has no log table");
        }
    });
});

describe('the change log', function (): void {
    it('records a create with who did it and from where', function (): void {
        $employee = ($this->register)();

        $log = ($this->logsFor)('employees', $employee['id'])->first();

        expect($log->action)->toBe('created')
            ->and($log->user_id)->toBe((string) $this->admin->id)
            ->and($log->url)->toBe('POST api/v1/employees')
            ->and($log->ip_address)->not->toBeNull()
            ->and($log->old_values)->toBeNull()
            ->and(json_decode($log->new_values, true))->toMatchArray(['first_name' => 'Marco']);
    });

    it('records only what an update changed, before and after', function (): void {
        $employee = ($this->register)();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/employees/{$employee['id']}", ['contact' => '0918 000 0000'])
            ->assertOk();

        $log = ($this->logsFor)('employees', $employee['id'])->firstWhere('action', 'updated');

        expect(json_decode($log->old_values, true))->toMatchArray(['contact' => '0917 555 0101'])
            ->and(json_decode($log->new_values, true))->toMatchArray(['contact' => '0918 000 0000'])
            ->and(json_decode($log->new_values, true))->not->toHaveKey('first_name');
    });

    it('records a delete with what was removed', function (): void {
        $employee = ($this->register)();

        $this->actingAs($this->admin)->deleteJson("/api/v1/employees/{$employee['id']}")->assertSuccessful();

        $log = ($this->logsFor)('employees', $employee['id'])->firstWhere('action', 'deleted');

        expect($log)->not->toBeNull()
            ->and(json_decode($log->old_values, true))->toMatchArray(['first_name' => 'Marco']);
    });

    it('never writes a hidden attribute such as a password', function (): void {
        $user = User::factory()->create(['password' => 'secret-password-1']);

        $log = ($this->logsFor)('users', (string) $user->id)->first();

        expect(json_decode($log->new_values, true))->not->toHaveKey('password')
            ->and($log->new_values)->not->toContain('secret-password-1');
    });

    it('writes nothing for a save that changed nothing', function (): void {
        $employee = Employee::findOrFail(($this->register)()['id']);

        $employee->save();

        expect(($this->logsFor)('employees', $employee->id))->toHaveCount(1);
    });
});

describe('fms:prefix-tables', function (): void {
    it('renames an unprefixed table and leaves prefixed ones alone', function (): void {
        DB::statement('create table "legacy_things" (id integer primary key)');

        $this->artisan('fms:prefix-tables', ['--dry-run' => true])->assertSuccessful();
        expect(($this->physicalTables)())->toContain('legacy_things');

        $this->artisan('fms:prefix-tables')->assertSuccessful();

        expect(($this->physicalTables)())->toContain('fms_legacy_things', 'fms_employees')
            ->not->toContain('legacy_things', 'fms_fms_employees');
    });

    it('skips a table whose prefixed name is already taken', function (): void {
        DB::statement('create table "employees" (id integer primary key)');

        $this->artisan('fms:prefix-tables')->assertSuccessful();

        expect(($this->physicalTables)())->toContain('employees', 'fms_employees');
    });
});
