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
            $table->decimal('origin_lat', 10, 7)->nullable()->after('origin');
            $table->decimal('origin_lng', 10, 7)->nullable()->after('origin_lat');
            $table->decimal('destination_lat', 10, 7)->nullable()->after('destination');
            $table->decimal('destination_lng', 10, 7)->nullable()->after('destination_lat');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn([
                'origin_lat', 'origin_lng',
                'destination_lat', 'destination_lng',
            ]);
        });
    }
};
