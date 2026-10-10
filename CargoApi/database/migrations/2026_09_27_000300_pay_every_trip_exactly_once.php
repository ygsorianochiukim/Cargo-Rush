<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_run_line_trips', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('pay_run_line_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('trip_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('employee_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['pay_run_line_id', 'trip_id']);
            $table->index(['employee_id', 'trip_id']);
        });

        Schema::table('pay_runs', function (Blueprint $table): void {
            $table->boolean('links_trips')->default(false)->after('status');
        });

        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->boolean('crew')->default(false)->after('pay_basis');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->dropColumn('crew');
        });

        Schema::table('pay_runs', function (Blueprint $table): void {
            $table->dropColumn('links_trips');
        });

        Schema::dropIfExists('pay_run_line_trips');
    }
};
