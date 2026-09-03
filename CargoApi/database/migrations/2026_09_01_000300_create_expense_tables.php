<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spend that is not one of the workbook's five columns.
 *
 * `ledger_entries` carries fuel, driver salary, helper salary, maintenance and
 * allowance, because those are the columns the transcribed workbook had. Real
 * days do not fit in five columns: the crew eats, the truck pays a toll, a
 * permit is renewed, somebody buys a tarpaulin. All of that was being pushed
 * into `allowance_cents` or left off the books entirely, and neither answers
 * the question the office actually asks — *what are we spending it on?*
 *
 * So expenses get a category and a row of their own, and the day's total
 * becomes the five columns plus whatever lines are filed against it. Additive
 * on purpose: the existing columns, the pages that read them and the seeded
 * workbook figures all keep working exactly as they did.
 *
 * The one thing to know when entering data: a line and a column are not
 * reconciled against each other. Logging a fill-up as a `Fuel` line *and*
 * keying it into `fuel_cents` counts it twice, because the system cannot tell
 * a duplicate from a second fill-up on the same day. The UI says so where the
 * two meet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The stable handle. Seeded categories are looked up by this, so
            // renaming "Food" to "Meals" in the UI does not orphan its rows.
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            // The icon the clients render it with, from the shared icon set —
            // a name, never a hex value (DESIGN.md section 7.1).
            $table->string('icon')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Restricted, not cascading: deleting a category that has spend
            // filed against it would silently remove the spend with it, and a
            // quarter that used to balance would stop. The service retires a
            // category instead.
            $table->foreignUlid('category_id')->constrained('expense_categories')->restrictOnDelete();
            /**
             * Which unit it belongs to. Null is a real and common case — the
             * office rent, an annual permit, a bulk tyre order — and those are
             * fleet overhead that belongs in the period total without being
             * attributed to any one truck's profitability.
             */
            $table->foreignUlid('truck_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('driver_id')->nullable()->constrained()->nullOnDelete();
            // The day's sheet this lands on, when it lands on one.
            $table->foreignUlid('ledger_entry_id')->nullable()->constrained()->nullOnDelete();
            // A date with no time, like the ledger it rolls into.
            $table->date('date');
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('PHP');
            $table->string('payee')->nullable();
            $table->string('reference')->nullable();
            $table->string('note')->nullable();
            // `pending` is a claim not yet approved and `cancelled` is a
            // rejected one; neither counts as spend. Only `active` does.
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
