<?php

declare(strict_types=1);

use App\Domain\Shared\Support\ChangeLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (ChangeLog::TABLES as $table) {
            ChangeLog::createTable($table);
        }
    }

    public function down(): void
    {
        foreach (ChangeLog::TABLES as $table) {
            Schema::dropIfExists(ChangeLog::logTable($table));
        }
    }
};
