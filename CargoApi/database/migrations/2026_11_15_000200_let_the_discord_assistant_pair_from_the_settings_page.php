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
        Schema::table('assistant_settings', function (Blueprint $table): void {
            $table->text('discord_token')->nullable()->after('llm_thinking');
            $table->string('discord_token_hint', 8)->nullable()->after('discord_token');
        });

        Schema::create('assistant_pairings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 9)->index();
            $table->string('secret_hash', 64);
            $table->foreignUlid('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('token')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        if (! Schema::hasTable(ChangeLog::logTable('assistant_pairings'))) {
            ChangeLog::createTable('assistant_pairings');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(ChangeLog::logTable('assistant_pairings'));
        Schema::dropIfExists('assistant_pairings');

        Schema::table('assistant_settings', function (Blueprint $table): void {
            $table->dropColumn(['discord_token', 'discord_token_hint']);
        });
    }
};
