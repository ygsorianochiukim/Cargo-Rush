<?php

declare(strict_types=1);

use App\Domain\Shared\Support\ChangeLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('llm_provider', 20)->default('deepseek');
            $table->string('llm_model', 80)->nullable();
            $table->text('llm_api_key')->nullable();
            $table->string('llm_key_hint', 8)->nullable();
            $table->boolean('llm_thinking')->default(false);
            $table->string('bot_role', 40)->default('accountant');
            $table->foreignId('bot_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('assistant_discord_users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('discord_id', 25);
            $table->string('label', 80)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'discord_id']);
        });

        foreach (['assistant_settings', 'assistant_discord_users'] as $logged) {
            if (! Schema::hasTable(ChangeLog::logTable($logged))) {
                ChangeLog::createTable($logged);
            }
        }
    }

    public function down(): void
    {
        foreach (['assistant_discord_users', 'assistant_settings'] as $logged) {
            Schema::dropIfExists(ChangeLog::logTable($logged));
            Schema::dropIfExists($logged);
        }
    }
};
