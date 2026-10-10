<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->boolean('sss_enrolled')->default(false)->change();
            $table->boolean('philhealth_enrolled')->default(false)->change();
            $table->boolean('pagibig_enrolled')->default(false)->change();

            $table->dropColumn('store_deduction_cap_cents');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->boolean('sss_enrolled')->default(true)->change();
            $table->boolean('philhealth_enrolled')->default(true)->change();
            $table->boolean('pagibig_enrolled')->default(true)->change();

            $table->unsignedBigInteger('store_deduction_cap_cents')
                ->default(0)
                ->after('pagibig_enrolled');
        });
    }
};
