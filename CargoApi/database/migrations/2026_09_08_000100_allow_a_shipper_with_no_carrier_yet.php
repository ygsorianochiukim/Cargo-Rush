<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 40)->nullable()->after('email');
        });

        $rebuildsTables = DB::getDriverName() === 'sqlite';

        if (! $rebuildsTables) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('company_id')->nullable()->change();
        });

        if (! $rebuildsTables) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        DB::table('users')->whereNull('company_id')->delete();

        $rebuildsTables = DB::getDriverName() === 'sqlite';

        if (! $rebuildsTables) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('company_id')->nullable(false)->change();
        });

        if (! $rebuildsTables) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('phone');
        });
    }
};
