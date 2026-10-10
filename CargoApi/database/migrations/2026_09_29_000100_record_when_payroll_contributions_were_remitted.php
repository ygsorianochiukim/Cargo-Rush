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
            $table->date('remitted_on')->nullable()->after('paid_at');
            $table->string('remittance_reference', 120)->nullable()->after('remitted_on');
            $table->ulid('remittance_entry_id')->nullable()->after('remittance_reference');
        });
    }

    public function down(): void
    {
        Schema::table('pay_runs', function (Blueprint $table): void {
            $table->dropColumn(['remitted_on', 'remittance_reference', 'remittance_entry_id']);
        });
    }
};
