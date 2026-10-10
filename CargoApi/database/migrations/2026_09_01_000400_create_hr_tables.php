<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('employee_no')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_name')->nullable();
            $table->string('position');
            $table->string('department')->nullable();
            $table->string('employment_type')->default('probationary');
            $table->string('status')->default('active');
            $table->date('hired_on');
            $table->date('birth_date')->nullable();
            $table->string('contact');
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('emergency_phone')->nullable();
            $table->unsignedBigInteger('base_salary_cents')->default(0);
            $table->string('photo_path')->nullable();
            $table->foreignUlid('driver_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('position');
        });

        Schema::create('applicants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('position_applied');
            $table->string('contact');
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('source')->nullable();
            $table->date('applied_on');
            $table->string('stage')->default('applied');
            $table->string('photo_path')->nullable();
            $table->string('resume_path')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUlid('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('stage');
        });

        Schema::create('user_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nav_key');
            $table->timestamps();

            $table->unique(['user_id', 'nav_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_modules');
        Schema::dropIfExists('applicants');
        Schema::dropIfExists('employees');
    }
};
