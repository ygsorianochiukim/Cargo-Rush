<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->string('role', 16)->default('driver')->after('user_id');
            $table->string('contact')->nullable()->after('name');
            $table->string('licence_no')->nullable()->change();
            $table->date('licence_expiry')->nullable()->change();

            $table->index(['company_id', 'role']);
        });

        Schema::table('trips', function (Blueprint $table): void {
            $table->boolean('zone_pinned')->default(false)->after('pricing_zone_id');
        });

        $helperPositions = DB::table('positions')->where('key', 'helper')->pluck('id');

        DB::table('drivers')
            ->whereIn('id', DB::table('employees')
                ->whereNotNull('driver_id')
                ->where(static fn ($q) => $q->whereIn('position_id', $helperPositions)->orWhere('position', 'Helper'))
                ->select('driver_id'))
            ->update(['role' => 'helper']);

        $this->enrolDriversAsEmployees();
    }

    private function enrolDriversAsEmployees(): void
    {
        $now = now();

        $drivers = DB::table('drivers')
            ->whereNull('deleted_at')
            ->whereNotIn('id', DB::table('employees')->whereNotNull('driver_id')->select('driver_id'))
            ->orderBy('created_at')
            ->get();

        foreach ($drivers as $driver) {
            $position = DB::table('positions')
                ->where('company_id', $driver->company_id)
                ->where('key', $driver->role)
                ->whereNull('deleted_at')
                ->first(['id', 'name']);

            [$first, $last] = $this->splitName((string) $driver->name);

            DB::table('employees')->insert([
                'id' => strtolower((string) Str::ulid()),
                'company_id' => $driver->company_id,
                'employee_no' => $this->nextEmployeeNo($driver->company_id),
                'first_name' => $first,
                'last_name' => $last,
                'position' => $position->name ?? ucfirst((string) $driver->role),
                'position_id' => $position->id ?? null,
                'employment_type' => 'probationary',
                'status' => 'active',
                'hired_on' => substr((string) ($driver->created_at ?? $now), 0, 10),
                'contact' => '',
                'driver_id' => $driver->id,
                'user_id' => DB::table('employees')->where('user_id', $driver->user_id)->exists() ? null : $driver->user_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        if (count($parts) < 2) {
            return [trim($name), ''];
        }

        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }

    private function nextEmployeeNo(string $companyId): string
    {
        $count = DB::table('employees')->where('company_id', $companyId)->count() + 1;

        do {
            $candidate = 'EMP-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
            $count++;
        } while (DB::table('employees')->where('company_id', $companyId)->where('employee_no', $candidate)->exists());

        return $candidate;
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn('zone_pinned');
        });

        Schema::table('drivers', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'role']);
            $table->dropColumn(['role', 'contact']);
        });
    }
};
