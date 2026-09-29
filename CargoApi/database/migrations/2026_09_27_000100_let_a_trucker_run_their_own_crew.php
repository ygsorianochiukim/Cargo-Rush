<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trucker is a business, and a business has drivers.
 *
 * Registration used to assume an owner-operator: one person, their licence,
 * their truck. A trucker now signs up as a trucking service — a name, a number
 * and a login — and once the office has approved them they add their trucks
 * and their drivers from the app.
 *
 * ## Why their drivers are not `drivers` rows
 *
 * `drivers` is Cargo Rush's own crew: Drivers Management, payroll, the daily
 * sheet's pickers and the driver app all read it. A trucker's driver is none of
 * those things — not on the payroll, not on the sheet, not the office's to
 * roster — and putting them in the same table would make every one of those
 * screens one missed `where` away from paying somebody else's employee. A table
 * of their own is separation nobody has to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('truckers', function (Blueprint $table): void {
            /** The trucking service's name — what the office and customers see. */
            $table->string('business_name', 160)->nullable()->after('name');

            // Optional now. The owner may never drive; each of their drivers
            // carries a licence of their own.
            $table->string('licence_no')->nullable()->change();
        });

        Schema::create('trucker_drivers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('trucker_id')->constrained()->cascadeOnDelete();

            /** Their own login to the app. Every driver the owner adds gets one. */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->string('licence_no');
            $table->date('licence_expiry')->nullable();

            /** `active`, or `inactive` once the owner stands them down. */
            $table->string('status')->default('active');

            $table->timestamps();
            $table->softDeletes();

            // One licence once per trucker. Another trucker employing the same
            // person is ordinary, and not this table's business to refuse.
            $table->unique(['trucker_id', 'licence_no']);
            $table->index(['trucker_id', 'status']);
        });

        Schema::table('trips', function (Blueprint $table): void {
            /**
             * Which of the trucker's drivers is running it. Null while the
             * owner runs it themselves, and on every Cargo Rush trip.
             */
            $table->foreignUlid('trucker_driver_id')->nullable()->after('trucker_vehicle_id')
                ->constrained('trucker_drivers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('trucker_driver_id');
        });

        Schema::dropIfExists('trucker_drivers');

        Schema::table('truckers', function (Blueprint $table): void {
            $table->dropColumn('business_name');
        });
    }
};
