<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_discord_users', function (Blueprint $table): void {
            $table->boolean('may_confirm_receipts')->default(false);
        });

        Schema::create('discord_expense_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->restrictOnDelete();
            $table->string('discord_user_id', 20);
            $table->string('channel_id', 20);
            $table->string('attachment_id', 20);
            $table->string('filename');
            $table->string('mime', 30);
            $table->longText('original');
            $table->char('fingerprint', 64);
            $table->char('posted_fingerprint', 64)->nullable();
            $table->string('status', 16);
            $table->unsignedInteger('revision')->default(0);
            $table->json('fields');
            $table->json('history');
            $table->foreignUlid('expense_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'attachment_id']);
            $table->unique(['company_id', 'posted_fingerprint'], 'discord_receipts_posted_fingerprint_unique');
            $table->index(['company_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_expense_receipts');
        Schema::table('assistant_discord_users', function (Blueprint $table): void {
            $table->dropColumn('may_confirm_receipts');
        });
    }
};
