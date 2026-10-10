<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('reference');

            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date');

            $table->string('status')->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            $table->foreignUlid('journal_entry_id')->nullable()->constrained()->nullOnDelete();

            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'reference']);
            $table->index(['company_id', 'period_start']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('pay_run_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('pay_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained()->restrictOnDelete();

            $table->string('employee_no');
            $table->string('name');
            $table->string('position')->nullable();

            $table->bigInteger('basic_cents')->default(0);
            $table->bigInteger('allowance_cents')->default(0);
            $table->bigInteger('overtime_cents')->default(0);
            $table->bigInteger('adjustments_cents')->default(0);
            $table->string('adjustment_note')->nullable();

            $table->bigInteger('sss_cents')->default(0);
            $table->bigInteger('philhealth_cents')->default(0);
            $table->bigInteger('pagibig_cents')->default(0);
            $table->bigInteger('withholding_tax_cents')->default(0);
            $table->bigInteger('other_deductions_cents')->default(0);
            $table->string('deduction_note')->nullable();

            $table->bigInteger('gross_cents')->default(0);
            $table->bigInteger('deductions_cents')->default(0);
            $table->bigInteger('net_cents')->default(0);

            $table->timestamps();

            $table->unique(['pay_run_id', 'employee_id']);
            $table->index(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_run_lines');
        Schema::dropIfExists('pay_runs');
    }
};
