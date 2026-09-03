<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `GET /api/v1/me` needs a role and an avatar; the base users table has
 * neither. `role` is the machine enum, the label is derived (DESIGN.md 7.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->default('driver')->after('email');
            $table->string('avatar_url')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['role', 'avatar_url']);
        });
    }
};
