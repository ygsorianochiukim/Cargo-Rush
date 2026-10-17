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
            $table->unsignedBigInteger('driver_allowance_cents')->nullable()->after('price_cents');
            $table->unsignedBigInteger('helper_allowance_cents')->nullable()->after('driver_allowance_cents');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn(['driver_allowance_cents', 'helper_allowance_cents']);
        });
    }
};
