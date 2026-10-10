<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_components', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            $table->string('kind');

            $table->string('basis')->default('fixed');

            $table->bigInteger('amount_cents')->default(0);
            $table->unsignedInteger('rate_bp')->default(0);

            $table->string('schedule')->default('each_run');

            $table->boolean('taxable')->default(false);

            $table->string('status')->default('active');
            $table->unsignedInteger('position')->default(0);
            $table->string('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('employee_pay_components', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('pay_component_id')->constrained()->cascadeOnDelete();

            $table->bigInteger('amount_cents')->nullable();
            $table->unsignedInteger('rate_bp')->nullable();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->string('status')->default('active');
            $table->string('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'employee_id']);
            $table->index(['company_id', 'pay_component_id']);
        });

        Schema::create('pay_run_line_components', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('pay_run_line_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('pay_component_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('kind');
            $table->boolean('taxable')->default(false);
            $table->bigInteger('amount_cents')->default(0);

            $table->timestamps();

            $table->index('pay_run_line_id');
        });

        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->bigInteger('component_earnings_cents')->default(0)->after('adjustment_note');
            $table->bigInteger('component_deductions_cents')->default(0)->after('other_deductions_cents');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->dropColumn(['component_earnings_cents', 'component_deductions_cents']);
        });

        Schema::dropIfExists('pay_run_line_components');
        Schema::dropIfExists('employee_pay_components');
        Schema::dropIfExists('pay_components');
    }
};
