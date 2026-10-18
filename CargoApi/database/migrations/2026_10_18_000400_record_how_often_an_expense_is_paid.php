<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('pay_schedule', 20)->default('on_receipt')->after('payment_source_id');
            $table->index(['company_id', 'pay_schedule']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'pay_schedule']);
            $table->dropColumn('pay_schedule');
        });
    }
};
