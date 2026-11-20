<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_settings', function (Blueprint $table): void {
            $table->string('discord_assistant_channel_id', 20)->nullable();
            $table->string('discord_finance_channel_id', 20)->nullable();
            $table->string('discord_finance_alerts_channel_id', 20)->nullable();
            $table->unsignedInteger('discord_payment_alert_pesos')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assistant_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'discord_assistant_channel_id', 'discord_finance_channel_id',
                'discord_finance_alerts_channel_id', 'discord_payment_alert_pesos',
            ]);
        });
    }
};
