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
            $table->foreignUlid('pricing_zone_id')->nullable()->after('currency')
                ->constrained('pricing_zones')->nullOnDelete();
            $table->foreignUlid('pricing_bracket_id')->nullable()->after('pricing_zone_id')
                ->constrained('pricing_brackets')->nullOnDelete();
            $table->integer('fuel_adjustment_bp')->default(0)->after('pricing_bracket_id');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pricing_zone_id');
            $table->dropConstrainedForeignId('pricing_bracket_id');
            $table->dropColumn('fuel_adjustment_bp');
        });
    }
};
