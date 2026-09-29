<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two records of the same money, each told about the other.
 *
 * ## A service and the garage's bill for it
 *
 * A costed maintenance job posts its cost to the daily sheet's
 * `maintenance_cents`. The garage's invoice for the same work, raised as a
 * payable in Billing and paid, was then counted a second time as a supplier
 * bill — one oil change, two expenses. `maintenance_jobs.invoice_id` says
 * which bill a job is, so the period reports can count the job and leave that
 * bill's payments out.
 *
 * On the job rather than on the invoice because the job is the one that posts:
 * it is the record that already carries `posted_cents`, and the question a
 * report asks is "is this bill's money already on a sheet?", which is a lookup
 * from the job side. A bill covering two jobs is two jobs pointing at it.
 *
 * ## A fill logged at /fuel and the sheet's Fuel column
 *
 * `/fuel` is now the one place fuel is recorded. An active fill posts itself
 * into its truck's row for the day, exactly as a maintenance job does, and the
 * three columns here are its `posted_cents`: what it has pushed, onto which
 * sheet, on which day — so an edit, a cancel or a moved date can take off
 * precisely what was put on, wherever that was. See `FuelPostingService`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_jobs', function (Blueprint $table): void {
            $table->foreignUlid('invoice_id')->nullable()->after('supplier_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('fuel_records', function (Blueprint $table): void {
            // Zero means nothing on any sheet: pending, cancelled, or a vehicle
            // no truck points at — which stays fleet overhead.
            $table->unsignedBigInteger('posted_cents')->default(0)->after('amount_cents');
            $table->foreignUlid('posted_truck_id')->nullable()->after('posted_cents')
                ->constrained('trucks')->nullOnDelete();
            $table->date('posted_on')->nullable()->after('posted_truck_id');
        });
    }

    public function down(): void
    {
        Schema::table('fuel_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('posted_truck_id');
            $table->dropColumn(['posted_cents', 'posted_on']);
        });

        Schema::table('maintenance_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
