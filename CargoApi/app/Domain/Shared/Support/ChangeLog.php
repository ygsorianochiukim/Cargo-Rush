<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class ChangeLog
{
    public const ACTIONS = ['created', 'updated', 'deleted', 'restored'];

    public const TABLES = [
        'accounts', 'applicants', 'asset_categories', 'asset_turnovers', 'assets', 'companies', 'contracts', 'customers', 'delivery_logs',
        'diesel_prices', 'dispatch_records', 'drivers', 'employee_pay_components', 'employees',
        'expense_categories', 'expenses', 'fuel_budgets', 'fuel_records', 'incidents',
        'inspections', 'invoices', 'journal_entries', 'journal_lines', 'leave_requests',
        'ledger_entries', 'ledger_entry_helpers', 'maintenance_jobs', 'nav_items',
        'notification_items', 'pay_components', 'pay_run_line_components', 'pay_run_line_trips',
        'pay_run_lines', 'pay_runs', 'payment_allocations', 'payment_sources', 'payments', 'payroll_cutoff_requests',
        'permissions', 'positions', 'pricing_brackets', 'pricing_zones', 'print_templates', 'roles', 'store_credits',
        'supplier_categories', 'suppliers', 'trips', 'truck_categories', 'truck_sizes', 'trucker_drivers',
        'trucker_vehicles', 'trucker_wallet_entries', 'truckers', 'trucks', 'undertime_requests',
        'users', 'vehicles',
    ];

    /** @var array<string, bool> */
    private static array $ready = [];

    public static function logTable(string $table): string
    {
        return $table.'_logs';
    }

    public static function createTable(string $table): void
    {
        Schema::create(self::logTable($table), static function (Blueprint $log): void {
            $log->id();
            $log->string('record_id', 36)->nullable()->index();
            $log->string('action', 16);
            $log->json('old_values')->nullable();
            $log->json('new_values')->nullable();
            $log->string('user_id', 36)->nullable()->index();
            $log->string('ip_address', 45)->nullable();
            $log->string('url', 255)->nullable();
            $log->timestamp('created_at')->useCurrent()->index();
        });

        self::$ready = [];
    }

    public static function record(string $action, Model $model): void
    {
        $table = $model->getTable();

        if (! in_array($action, self::ACTIONS, true) || ! in_array($table, self::TABLES, true)) {
            return;
        }

        $connection = $model->getConnection();
        $logTable = self::logTable($table);

        if (! (self::$ready[$logTable] ??= $connection->getSchemaBuilder()->hasTable($logTable))) {
            unset(self::$ready[$logTable]);

            return;
        }

        [$old, $new] = match ($action) {
            'created', 'restored' => [null, $model->getAttributes()],
            'updated' => [
                array_intersect_key($model->getRawOriginal(), $model->getChanges()),
                $model->getChanges(),
            ],
            'deleted' => [$model->getAttributes(), null],
        };

        if ($action === 'updated' && $new === []) {
            return;
        }

        $request = request();
        $routed = $request->route() !== null;

        $connection->table($logTable)->insert([
            'record_id' => $model->getKey() === null ? null : (string) $model->getKey(),
            'action' => $action,
            'old_values' => self::encode($old, $model),
            'new_values' => self::encode($new, $model),
            'user_id' => auth()->id() === null ? null : (string) auth()->id(),
            'ip_address' => $routed ? $request->ip() : null,
            'url' => $routed ? mb_substr($request->method().' '.$request->path(), 0, 255) : null,
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed>|null $values */
    private static function encode(?array $values, Model $model): ?string
    {
        if ($values === null) {
            return null;
        }

        $values = array_diff_key($values, array_flip($model->getHidden()));

        return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: null;
    }
}
