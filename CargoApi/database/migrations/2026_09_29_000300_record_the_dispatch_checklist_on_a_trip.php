<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->json('dispatch_checklist')->nullable();
            $table->string('dispatch_remarks', 500)->nullable();
            $table->timestamp('dispatch_checked_at')->nullable();
            $table->string('dispatch_checked_by', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn(['dispatch_checklist', 'dispatch_remarks', 'dispatch_checked_at', 'dispatch_checked_by']);
        });
    }
};
