<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('days', 4, 1)->default(0);
            $table->string('reason');
            $table->string('status')->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'starts_on']);
            $table->index('status');
        });

        Schema::create('undertime_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('from_time');
            $table->time('to_time');
            $table->decimal('hours', 5, 2)->default(0);
            $table->string('reason');
            $table->string('status')->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('undertime_requests');
        Schema::dropIfExists('leave_requests');
    }
};
