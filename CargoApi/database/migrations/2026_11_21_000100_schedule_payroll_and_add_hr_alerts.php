<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->json('payroll_pay_dates')->nullable();
            $table->boolean('payroll_auto_drafts')->default(false);
        });

        Schema::table('assistant_settings', function (Blueprint $table): void {
            $table->string('discord_hr_channel_id', 20)->nullable();
        });

        Schema::create('payroll_discord_alerts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('pay_run_id')->constrained()->cascadeOnDelete();
            $table->string('event', 16);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['pay_run_id', 'event']);
            $table->index(['company_id', 'delivered_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_discord_alerts');
        Schema::table('assistant_settings', fn (Blueprint $table) => $table->dropColumn('discord_hr_channel_id'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['payroll_pay_dates', 'payroll_auto_drafts']));
    }
};
