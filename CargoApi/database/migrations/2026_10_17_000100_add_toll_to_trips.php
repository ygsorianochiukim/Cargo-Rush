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
            $table->unsignedBigInteger('toll_cents')->nullable()->after('helper_allowance_cents');
        });

        Schema::table('delivery_logs', function (Blueprint $table): void {
            $table->string('posted_toll_expense_id', 26)->nullable()->after('posted_helper_allowance_cents');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table): void {
            $table->dropColumn('posted_toll_expense_id');
        });

        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn('toll_cents');
        });
    }
};
