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
        Schema::table('trips', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_cents')->nullable()->default(null)->change();
            $table->string('pricing_source', 16)->nullable()->after('pricing_bracket_id');
            $table->string('pricing_note', 255)->nullable()->after('pricing_source');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn(['pricing_source', 'pricing_note']);
        });

        DB::table('trips')->whereNull('price_cents')->update(['price_cents' => 0]);

        Schema::table('trips', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_cents')->default(0)->nullable(false)->change();
        });
    }
};
