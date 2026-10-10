<?php

declare(strict_types=1);

namespace App\Domain\Shared\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PrefixTablesCommand extends Command
{
    protected $signature = 'fms:prefix-tables {--dry-run : List the renames without making them}';

    protected $description = 'Rename this database\'s unprefixed tables to carry the configured table prefix';

    public function handle(): int
    {
        $connection = DB::connection();
        $prefix = $connection->getTablePrefix();

        if ($prefix === '') {
            $this->error('No table prefix is configured (DB_PREFIX).');

            return self::FAILURE;
        }

        $tables = $this->tables();
        $renamed = 0;

        foreach ($tables as $table) {
            if (str_starts_with($table, $prefix)) {
                continue;
            }

            $target = $prefix.$table;

            if (in_array($target, $tables, true)) {
                $this->warn("Skipped {$table}: {$target} already exists.");

                continue;
            }

            $this->line("{$table} → {$target}");

            if (! $this->option('dry-run')) {
                $connection->statement($this->renameSql($table, $target));
            }

            $renamed++;
        }

        $this->info($this->option('dry-run')
            ? "{$renamed} table(s) would be renamed."
            : "{$renamed} table(s) renamed.");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function tables(): array
    {
        $connection = DB::connection();

        $rows = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->select(
                "select table_name as name from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE'"
            ),
            'sqlite' => $connection->select(
                "select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"
            ),
            default => throw new \RuntimeException('Unsupported driver: '.$connection->getDriverName()),
        };

        return array_values(array_map(static fn (object $row): string => (string) $row->name, $rows));
    }

    private function renameSql(string $from, string $to): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "rename table `{$from}` to `{$to}`",
            default => "alter table \"{$from}\" rename to \"{$to}\"",
        };
    }
}
