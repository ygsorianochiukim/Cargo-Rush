<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->boolean('sss_enrolled')->default(true)->after('daily_rate_cents');
            $table->boolean('philhealth_enrolled')->default(true)->after('sss_enrolled');
            $table->boolean('pagibig_enrolled')->default(true)->after('philhealth_enrolled');

            $table->unsignedBigInteger('store_deduction_cap_cents')
                ->default(0)
                ->after('pagibig_enrolled');
        });

        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->boolean('sss_enrolled')->default(true)->after('sss_cents');
            $table->boolean('philhealth_enrolled')->default(true)->after('philhealth_cents');
            $table->boolean('pagibig_enrolled')->default(true)->after('pagibig_cents');

            $table->bigInteger('store_deduction_cents')->default(0)->after('other_deductions_cents');
        });

        Schema::create('store_credits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 20);

            $table->unsignedBigInteger('amount_cents');

            $table->string('description')->nullable();

            $table->string('outlet')->nullable();

            $table->date('charged_on');

            $table->foreignUlid('pay_run_line_id')->nullable()
                ->constrained('pay_run_lines')->nullOnDelete();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'employee_id', 'charged_on']);
            $table->index(['company_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_credits');

        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'sss_enrolled', 'philhealth_enrolled', 'pagibig_enrolled',
                'store_deduction_cents',
            ]);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn([
                'sss_enrolled', 'philhealth_enrolled', 'pagibig_enrolled',
                'store_deduction_cap_cents',
            ]);
        });
    }
};
