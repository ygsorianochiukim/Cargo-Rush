<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('workflow', 80);
            $table->string('trigger', 16);
            $table->string('status', 16);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->index(['company_id', 'started_at', 'id']);
            $table->index(['company_id', 'status', 'started_at']);
        });

        Schema::table('invoices_logs', function (Blueprint $table): void {
            $table->foreignUlid('automation_run_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['automation_run_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices_logs', function (Blueprint $table): void {
            $table->dropIndex(['automation_run_id', 'id']);
            $table->dropConstrainedForeignId('automation_run_id');
        });

        Schema::dropIfExists('automation_runs');
    }
};
