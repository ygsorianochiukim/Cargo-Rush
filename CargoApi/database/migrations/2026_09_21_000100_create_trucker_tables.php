<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The third person holding the app: somebody who owns a truck and hauls with it.
 *
 * The system has had two kinds of operator since it was written, and both are
 * inside the business. A `drivers` row is an employee — a contract, a payslip,
 * a salary column on the day's ledger sheet. A `customers` row is a firm with a
 * pallet. Neither describes a man with his own ten-wheeler who will take a run
 * this week and none next, and who is paid a share of what the run billed
 * rather than a wage.
 *
 * That is what these tables are for, and the shape follows from one decision:
 * **a trucker operates inside the company's dispatch, not beside it.** Every
 * run they take is an ordinary `trips` row in the haulier's own books, with the
 * same reference, the same delivery log and the same proof of delivery. What is
 * different is who gets paid and how much — which is the wallet, below.
 *
 * The alternative was to make each trucker a company of their own and let the
 * existing carrier directory match them to shippers. It was rejected for a
 * reason worth writing down: the commission would then have to cross a tenant
 * boundary, and the one property this whole schema is built to guarantee is
 * that nothing crosses a tenant boundary. A partner inside the tenancy keeps
 * the money arithmetic in one set of books, where it can be audited.
 *
 * ## The three tables
 *
 * `truckers` is the partner. It looks like a `drivers` row and is deliberately
 * not one: there is no contract, no payslip and no salary, and in exchange
 * there is a standing (are they vetted?), a switch (are they taking work right
 * now?) and a last known position (are they near this load?).
 *
 * `trucker_vehicles` is their unit. Not a `vehicles` row, and this is the
 * second decision worth defending. The fleet table carries an odometer, a
 * service interval and a fuel budget, and every fleet-utilisation figure in the
 * system counts its rows — a partner's truck landing there would inflate the
 * fleet the haulier owns, put somebody else's maintenance on the schedule, and
 * open a daily ledger sheet for a unit whose costs are none of the company's
 * business. What the desk actually needs to know about a partner's truck is
 * what it can carry, and that is what is here.
 *
 * `trucker_wallet_entries` is the money. See the model for what a positive and
 * a negative balance each mean; the short version is that one row is written
 * per delivered run and the sign says which way the money owes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('truckers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            /**
             * Their login. Nullable for the same reason `drivers.user_id` is —
             * the desk may put a partner on the books before they have the app,
             * and a trucker who registered themselves has one from the first
             * second.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            /** What dispatch rings. Required, unlike on a driver: a partner is not down the corridor. */
            $table->string('phone', 40);

            /**
             * They drive their own unit, so the licence is the company's
             * business in exactly the way an employee's is — it is what the
             * desk is relying on when it hands them a load.
             *
             * Not unique across the table, unlike `drivers.licence_no`. A man
             * can be on the payroll of one haulier and a partner of another on
             * this same platform, and a system-wide index would refuse the
             * second row with a message about a duplicate licence rather than
             * the truth, which is that the number is already known elsewhere.
             * Uniqueness is per company, below.
             */
            $table->string('licence_no');
            $table->date('licence_expiry')->nullable();

            /**
             * Their standing with the haulier — from the shared status
             * vocabulary, so the clients colour it like everything else.
             *
             *   `pending`   registered, nobody has looked at them yet
             *   `active`    vetted; may take work
             *   `inactive`  suspended, or they stopped hauling
             *
             * `pending` is the default and that is the whole point of the
             * column: somebody who signs themselves up on a phone has not been
             * vetted by anybody, and a platform that let them take a customer's
             * load the same minute would be handing a stranger a pallet. The
             * job board refuses anything but `active`.
             */
            $table->string('status')->default('pending');

            /**
             * Are they taking work right now?
             *
             * Separate from `status`, and the two are genuinely orthogonal: a
             * vetted partner asleep at 3am is `active` and offline, and a
             * suspended one who left the switch on is `inactive` and online.
             * Collapsing them into one column — which is what the driver roster
             * does, because an employee's availability is the only question
             * there — would mean a suspension being undone by the partner
             * flipping their own switch.
             */
            $table->boolean('is_online')->default(false);

            /**
             * What the haulier keeps of what this partner's runs bill, in basis
             * points. Null means the company's rate, which is where it should
             * normally stay.
             *
             * Per trucker because a rate is a negotiation: a partner who brings
             * their own customers, or one running six units, will ask for
             * better than the standing terms, and an office that cannot say yes
             * without a deployment will say no. Basis points rather than a
             * decimal, like every other rate here, so there is no float near a
             * peso.
             */
            $table->unsignedSmallInteger('commission_bp')->nullable();

            /**
             * Where they were when they last said.
             *
             * This is what "nearest" is measured from on the job board, and it
             * is reported by the handset rather than derived from a trip —
             * `gps_pings` hang off a run, and the whole question here is who is
             * near a load that has no run yet.
             *
             * `located_at` is not decoration: a position with no time on it is
             * indistinguishable from one from last Tuesday, and sorting a job
             * board by a stale pin sends a load to somebody two provinces away.
             */
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('located_at')->nullable();

            $table->unsignedInteger('trips_completed')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // One partner per licence per haulier. Per company rather than
            // system-wide, for the reason on the column itself.
            $table->unique(['company_id', 'licence_no']);
            $table->index(['company_id', 'status']);
            // The job board's own read: who is vetted, switched on, and near.
            $table->index(['company_id', 'status', 'is_online']);
        });

        Schema::create('trucker_vehicles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('trucker_id')->constrained()->cascadeOnDelete();

            $table->string('plate');
            $table->string('model');
            $table->unsignedInteger('capacity_kg');

            /**
             * Which kind of unit it is, against the same catalogue the rate
             * card prices from — so a load that needs a freezer is only ever
             * offered to somebody with one.
             *
             * Null on delete rather than cascade, exactly as the rate card does
             * it: retiring a category should widen the truck to "unspecified",
             * not delete a partner's only unit.
             */
            $table->foreignUlid('truck_category_id')->nullable()
                ->constrained('truck_categories')->nullOnDelete();

            /** `available`, or `maintenance` while it is in the shop. */
            $table->string('status')->default('available');

            $table->timestamps();
            $table->softDeletes();

            // A plate is unique to the haulier's books, not to the platform:
            // the same truck may be registered with two hauliers, which is
            // ordinary for a partner and is not this table's business to refuse.
            $table->unique(['company_id', 'plate']);
            $table->index(['trucker_id', 'status']);
        });

        Schema::create('trucker_wallet_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('trucker_id')->constrained()->cascadeOnDelete();

            /**
             * The run this row is about. Null on a payout or an adjustment,
             * which are about the account rather than about one haul.
             *
             * Null on delete, not cascade. A deleted trip must not take the
             * money with it — what the partner earned in March is still what
             * they earned in March, and a balance that silently moved because
             * somebody tidied the trip board is the one failure a wallet cannot
             * have.
             */
            $table->foreignUlid('trip_id')->nullable()->constrained()->nullOnDelete();

            /** `earning`, `commission`, `payout`, `remittance`, `adjustment` — see `WalletEntryKind`. */
            $table->string('kind');

            /**
             * Where the work came from: `cargo_rush` or `direct`.
             *
             * The audit column, and the reason it is on the money row rather
             * than only on the trip: a balance has to be defensible a year
             * later, to a partner asking why a figure is what it is, and
             * "because the trip said so" stops being an answer the moment a
             * trip is corrected. It is copied from `trips.booking_source` at
             * the moment the entry is written and never touched again.
             *
             * Null on a payout, which came from neither.
             */
            $table->string('source')->nullable();

            /**
             * Signed centavos, and the sign is the whole design.
             *
             * Positive moves the balance towards the partner: what they earned
             * on a run the haulier billed, and a remittance they have paid in.
             * Negative moves it towards the haulier: commission on a run they
             * collected themselves, and a payout once it has been handed over.
             *
             * The balance is the sum, and a signed sum needs no case analysis
             * anywhere — which is exactly what a `debit`/`credit` pair of
             * columns would have needed at every call site.
             */
            $table->bigInteger('amount_cents');

            /**
             * What the run billed, before the split. Null where there was no
             * run.
             *
             * Stored rather than read off the trip for the same reason `source`
             * is: this row has to still explain itself after the trip has been
             * re-priced, corrected or deleted.
             */
            $table->bigInteger('gross_cents')->nullable();
            /** The rate applied, in basis points. Frozen here for the same reason. */
            $table->unsignedSmallInteger('rate_bp')->nullable();

            /** A cheque number, a transfer reference, whatever the office has. */
            $table->string('reference')->nullable();
            $table->string('note')->nullable();

            /** The day it belongs to. A wallet statement is read by date, not by insertion order. */
            $table->date('occurred_on');

            /** Who at the desk recorded it. Null on the rows the system writes itself. */
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The statement: one partner's rows, by date.
            $table->index(['trucker_id', 'occurred_on']);
            $table->index(['company_id', 'kind']);

            /**
             * One money row of a kind per run, and the database is what says so
             * rather than a service remembering to check.
             *
             * Delivering credits the wallet, and delivering twice — a re-press
             * on a bad signal, or the office closing a run the partner already
             * closed — would credit it twice. `trips.billed_at` guards that
             * too, and this is the belt to those braces: the one thing a
             * partner will check is their balance, and a balance that can be
             * inflated by pressing a button again is not a wallet.
             *
             * Payouts, remittances and adjustments carry a null `trip_id`, and
             * SQL does not consider two nulls equal — so any number of them may
             * sit beside the one entry a trip is allowed.
             */
            $table->unique(['trip_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trucker_wallet_entries');
        Schema::dropIfExists('trucker_vehicles');
        Schema::dropIfExists('truckers');
    }
};
