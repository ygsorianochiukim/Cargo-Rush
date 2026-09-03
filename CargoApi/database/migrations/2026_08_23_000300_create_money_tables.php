<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything with a peso in it — fuel, billing, and the trip ledger.
 *
 * Money is integer minor units plus a currency string (DESIGN.md section 7.1).
 * There is no float column anywhere in this file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('driver_id')->nullable()->constrained()->nullOnDelete();
            // Litres is a measurement, not money, so two decimals is honest here.
            $table->decimal('litres', 8, 2);
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('PHP');
            $table->unsignedInteger('odometer_km');
            $table->string('receipt_no');
            $table->timestamp('logged_at');
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->softDeletes();
        });

        // One row per day. The Fuel module reads today; the projection is
        // computed from the history, never stored as a guess.
        Schema::create('fuel_budgets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->date('date')->unique();
            $table->unsignedBigInteger('daily_budget_cents');
            $table->string('currency', 3)->default('PHP');
            $table->unsignedSmallInteger('open_requests')->default(0);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('number')->unique();
            // Receivables point at a customer; payables name an outside payee.
            $table->foreignUlid('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payee')->nullable();
            $table->date('issued_at');
            $table->date('due_at');
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('PHP');
            $table->string('direction')->default('receivable');
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['direction', 'status']);
        });

        // Finance — the workbook. One sheet per truck becomes one row here.
        Schema::create('trucks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // "Truck 1" — the workbook TRUCK NO. column.
            $table->string('label');
            // Units 7 and 8 exist with no plate and must still render.
            $table->string('plate')->nullable();
            $table->foreignUlid('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('truck_id')->constrained()->cascadeOnDelete();
            // The ledger is daily, so this is a date with no time.
            $table->date('date');
            $table->bigInteger('trip_income_cents')->default(0);
            $table->bigInteger('fuel_cents')->default(0);
            $table->bigInteger('driver_salary_cents')->default(0);
            $table->bigInteger('helper_salary_cents')->default(0);
            $table->bigInteger('maintenance_cents')->default(0);
            $table->bigInteger('allowance_cents')->default(0);
            $table->string('route')->nullable();
            $table->string('remarks')->nullable();
            // Set when the driver recorded it from the cab rather than the office.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['truck_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('trucks');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('fuel_budgets');
        Schema::dropIfExists('fuel_records');
    }
};
