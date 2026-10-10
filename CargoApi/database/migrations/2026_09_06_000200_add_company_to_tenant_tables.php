<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string, string[]> */
    private const TENANT_TABLES = [
        'users' => [],
        'roles' => ['key'],
        'positions' => ['key'],

        'drivers' => ['licence_no'],
        'vehicles' => ['plate'],
        'customers' => [],
        'maintenance_jobs' => [],

        'trips' => ['reference'],
        'gps_pings' => [],
        'dispatch_records' => [],
        'delivery_logs' => [],
        'incidents' => [],
        'inspections' => [],

        'fuel_records' => [],
        'fuel_budgets' => ['date'],
        'invoices' => ['number'],
        'trucks' => [],
        'ledger_entries' => [],
        'expense_categories' => ['key'],
        'expenses' => [],

        'pricing_zones' => ['code'],
        'pricing_brackets' => [],
        'diesel_prices' => ['effective_on'],

        'employees' => ['employee_no'],
        'applicants' => [],
        'leave_requests' => [],
        'undertime_requests' => [],

        'notification_items' => [],
    ];

    /** @var array<string, string[]> */
    private const EXTRA_SCOPED_UNIQUES = [
        'incidents' => ['reference'],
    ];

    public function up(): void
    {
        foreach (array_keys(self::TENANT_TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->ulid('company_id')->nullable()->after('id');
            });
        }

        $companyId = $this->defaultCompany();

        foreach (array_keys(self::TENANT_TABLES) as $table) {
            DB::table($table)->whereNull('company_id')->update(['company_id' => $companyId]);
        }

        foreach (array_keys(self::TENANT_TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->ulid('company_id')->nullable(false)->change();
                $blueprint->index('company_id');
                $blueprint->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            });
        }

        foreach ($this->scopedUniques() as $table => $columns) {
            foreach ($columns as $column) {
                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->dropUnique([$column]);
                    $blueprint->unique(['company_id', $column]);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->scopedUniques() as $table => $columns) {
            foreach ($columns as $column) {
                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->dropUnique(['company_id', $column]);
                    $blueprint->unique([$column]);
                });
            }
        }

        foreach (array_keys(self::TENANT_TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['company_id']);
                $blueprint->dropIndex(['company_id']);
                $blueprint->dropColumn('company_id');
            });
        }
    }

    /** @return array<string, string[]> */
    private function scopedUniques(): array
    {
        $uniques = array_filter(self::TENANT_TABLES);

        foreach (self::EXTRA_SCOPED_UNIQUES as $table => $columns) {
            $uniques[$table] = array_merge($uniques[$table] ?? [], $columns);
        }

        return $uniques;
    }

    private function defaultCompany(): string
    {
        $name = (string) env('DEFAULT_COMPANY_NAME', 'Cargo Rush');
        $code = Str::slug((string) env('DEFAULT_COMPANY_CODE', $name)) ?: 'cargo-rush';

        $existing = DB::table('companies')->where('code', $code)->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::ulid();

        DB::table('companies')->insert([
            'id' => $id,
            'name' => $name,
            'code' => $code,
            'contact_email' => env('DEFAULT_COMPANY_EMAIL'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
