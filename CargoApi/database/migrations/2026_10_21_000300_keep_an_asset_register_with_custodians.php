<?php

declare(strict_types=1);

use App\Domain\Shared\Support\ChangeLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TABLES = ['asset_categories', 'assets', 'asset_turnovers'];

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private const PERMISSIONS = [
        ['assets.view', 'View assets', 'The asset register and who holds each item.'],
        ['assets.manage', 'Manage assets', 'Register assets and record every turnover between custodians.'],
    ];

    private const ROLES = ['general-manager', 'hr-officer'];

    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });

        Schema::create('assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained('asset_categories')->nullOnDelete();

            $table->string('tag', 40)->nullable();
            $table->string('name', 120);
            $table->string('serial_no', 80)->nullable();
            $table->date('purchased_on')->nullable();
            $table->unsignedBigInteger('cost_cents')->nullable();
            $table->foreignUlid('supplier_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignUlid('custodian_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('assigned_on')->nullable();
            $table->string('location', 120)->nullable();
            $table->string('condition', 20)->default('good');
            $table->string('status', 20)->default('available');
            $table->string('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'tag']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('asset_turnovers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('from_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignUlid('to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('turned_over_on');
            $table->string('condition', 20)->default('good');
            $table->string('status', 20);
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_id', 'turned_over_on']);
        });

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable(ChangeLog::logTable($table))) {
                ChangeLog::createTable($table);
            }
        }

        $this->grant();
    }

    private function grant(): void
    {
        $now = now();
        $ids = [];

        foreach (self::PERMISSIONS as $index => [$key, $name, $description]) {
            $id = DB::table('permissions')->where('key', $key)->value('id');

            if ($id === null) {
                $id = strtolower((string) Str::ulid());

                DB::table('permissions')->insert([
                    'id' => $id,
                    'key' => $key,
                    'name' => $name,
                    'group' => 'Assets',
                    'description' => $description,
                    'position' => 175 + $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $ids[] = $id;
        }

        foreach (DB::table('roles')->whereIn('key', self::ROLES)->pluck('id') as $roleId) {
            foreach ($ids as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists(ChangeLog::logTable($table));
            Schema::dropIfExists($table);
        }

        DB::table('permissions')->whereIn('key', array_column(self::PERMISSIONS, 0))->delete();
    }
};
