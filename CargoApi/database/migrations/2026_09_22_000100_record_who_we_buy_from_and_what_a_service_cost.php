<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the money went to, and what a service actually came to.
 *
 * Two gaps that turned out to be the same gap, which is why they are one
 * migration.
 *
 * ## A maintenance job knew when, and never knew how much
 *
 * `maintenance_jobs` recorded that a unit was due an oil change on the 12th and
 * nothing else. So the only place the ₱3,200 could go was an `expenses` row
 * with a truck hung off it — which meant the Other Expenses screen, a list
 * otherwise full of meals and tarpaulins, was also where the fleet's servicing
 * lived. The two were filed together because there was nowhere else to file
 * one of them, not because they are the same kind of spend.
 *
 * A job now carries its cost, the day it was done and who did it. `posted_cents`
 * is what makes that safe to edit: the figure already pushed onto the daily
 * sheet, so correcting ₱3,200 to ₱3,500 moves the sheet by ₱300 rather than by
 * another ₱3,500. See `MaintenanceService`.
 *
 * ## "Bought from" was a string somebody typed
 *
 * `expenses.payee` and `invoices.payee` are free text, so the same garage is
 * "Davao Lubes", "Davao lubes & parts" and "DAVAO LUBES" across one year, and
 * the question "what do we spend there" cannot be asked at all. `suppliers` is
 * that record, and the three places money leaves — an expense, a service, a
 * bill — all point at it.
 *
 * The `payee` columns stay. They hold what was already typed, they are what a
 * row falls back to when no supplier is named, and a bill from somebody the
 * office will never buy from again does not deserve a record of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('contact')->nullable();
            $table->string('address')->nullable();

            /**
             * What they sell, in the office's own words.
             *
             * Deliberately not a category: a garage that also sells tyres and
             * lends a flatbed is three categories, and forcing a choice would
             * make the record wrong in a way nobody could correct. A sentence
             * is what somebody actually needs when deciding who to ring.
             */
            $table->string('supplies')->nullable();
            $table->string('note')->nullable();

            $table->string('status')->default('active');

            $table->timestamps();
            $table->softDeletes();

            // One name per haulier. Per company rather than system-wide for the
            // reason every other unique in this system is: two fleets buying
            // from the same garage each keep their own record of it, with their
            // own contact and their own note.
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'status']);
        });

        Schema::table('maintenance_jobs', function (Blueprint $table): void {
            /**
             * What the job cost, and who did it.
             *
             * Nullable, because a job is booked before it is done and most of
             * the life of a row is the part where the answer is not known yet.
             * Null means "not costed", which is a different fact from zero —
             * zero is a warranty job somebody was not charged for.
             */
            $table->unsignedBigInteger('cost_cents')->nullable()->after('next_service_km');

            /**
             * What has already been pushed onto the daily sheet.
             *
             * The guard that makes the cost editable. A correction applies the
             * difference; a row saved twice applies nothing the second time.
             * Never null and never negative — zero means nothing posted yet.
             */
            $table->unsignedBigInteger('posted_cents')->default(0)->after('cost_cents');

            // The day the work was done, which is the day it is charged to.
            // Not `due_at`: a service booked for the 12th and done on the 19th
            // belongs to the 19th, which is when the money left.
            $table->date('completed_on')->nullable()->after('posted_cents');

            $table->foreignUlid('supplier_id')->nullable()->after('completed_on')
                ->constrained()->nullOnDelete();

            $table->string('reference')->nullable()->after('supplier_id');
            $table->string('note')->nullable()->after('reference');
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreignUlid('supplier_id')->nullable()->after('driver_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            // Payables only. A receivable names a customer, and that is a
            // different table for a different relationship — somebody who buys
            // hauling from this fleet rather than sells it something.
            $table->foreignUlid('supplier_id')->nullable()->after('customer_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_id');
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_id');
        });

        Schema::table('maintenance_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['cost_cents', 'posted_cents', 'completed_on', 'reference', 'note']);
        });

        Schema::dropIfExists('suppliers');
    }
};
