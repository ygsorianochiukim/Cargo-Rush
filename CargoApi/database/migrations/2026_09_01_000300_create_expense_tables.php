<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->foreignUlid('truck_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('ledger_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('PHP');
            $table->string('payee')->nullable();
            $table->string('reference')->nullable();
            $table->string('note')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['date', 'status']);
            $table->index(['truck_id', 'date']);
            $table->index(['category_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
