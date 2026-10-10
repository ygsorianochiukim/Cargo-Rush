<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_runs', function (Blueprint $table): void {
            $table->string('remittance_no', 20)->nullable()->after('remitted_on');
            $table->unique(['company_id', 'remittance_no']);
        });
    }

    public function down(): void
    {
        Schema::table('pay_runs', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'remittance_no']);
            $table->dropColumn('remittance_no');
        });
    }
};
