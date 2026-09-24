<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A run can carry more than one helper, and each is paid their own figure.
 *
 * `trips.helper_id` and `ledger_entries.helper_id` held one person, which was
 * the workbook's shape and not the yard's: a heavy load goes out with two or
 * three people to lift it. With one column, the second helper was either left
 * off the record — and so off the payslip — or typed into the remarks.
 *
 * Two child tables replace the columns:
 *
 *   `trip_helpers`, who rode on a run, in the order the desk named them;
 *   `ledger_entry_helpers`, what each helper on a day's sheet was paid.
 *
 * The sheet's line carries its own salary because the helpers on one day are
 * not paid the same — the one who has been doing it for five years is not on
 * the new one's rate. `ledger_entries.helper_salary_cents` stays, as the sum
 * of the lines, written in the same transaction: every roll-up, Profitability
 * and the Quarterly Summary among them, reads that one column, and making
 * each of them sum a child table would be a dozen changes for no difference in
 * the answer.
 *
 * A line's `driver_id` is nullable, like the column it replaces. A day's
 * helper pay entered without saying whose it was is still a real cost of the
 * day; it is counted toward nobody's payslip, which is the safe direction.
 *
 * No `company_id` on either table. A row is only ever reached through its trip
 * or its sheet day, and both of those are scoped to the company already.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_helpers', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('driver_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            // Once each. The same person twice on one run is a typing error,
            // and would be paid for the run twice.
            $table->unique(['trip_id', 'driver_id']);
            $table->index('driver_id');
        });

        Schema::create('ledger_entry_helpers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('ledger_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('salary_cents')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('driver_id');
        });

        $now = now();

        DB::table('trips')->whereNotNull('helper_id')->orderBy('id')->chunkById(500, function ($trips) use ($now): void {
            DB::table('trip_helpers')->insert($trips->map(static fn ($trip): array => [
                'trip_id' => $trip->id,
                'driver_id' => $trip->helper_id,
                'position' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        // A line for every day that named a helper or paid one — including the
        // ones that paid somebody without saying who, so the day's total is
        // carried over to the centavo.
        DB::table('ledger_entries')
            ->where(static fn ($q) => $q->whereNotNull('helper_id')->orWhere('helper_salary_cents', '>', 0))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($now): void {
                DB::table('ledger_entry_helpers')->insert($rows->map(static fn ($row): array => [
                    'id' => (string) Str::ulid(),
                    'ledger_entry_id' => $row->id,
                    'driver_id' => $row->helper_id,
                    'salary_cents' => (int) $row->helper_salary_cents,
                    'position' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'helper_id', 'date']);
            $table->dropConstrainedForeignId('helper_id');
        });

        Schema::table('trips', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('helper_id');
        });
    }

    /**
     * Back to one helper per run: the first one named.
     *
     * Lossy by nature — the second and third helpers have nowhere to go — but
     * the sheet's total stays right, because `helper_salary_cents` was never
     * dropped.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->foreignUlid('helper_id')->nullable()->after('driver_id')->constrained('drivers')->nullOnDelete();
        });

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->foreignUlid('helper_id')->nullable()->after('driver_id')->constrained('drivers')->nullOnDelete();
            $table->index(['company_id', 'helper_id', 'date']);
        });

        DB::table('trip_helpers')->orderBy('position')->get()->groupBy('trip_id')
            ->each(static fn ($lines, $tripId) => DB::table('trips')
                ->where('id', $tripId)->update(['helper_id' => $lines->first()->driver_id]));

        DB::table('ledger_entry_helpers')->whereNotNull('driver_id')->orderBy('position')->get()->groupBy('ledger_entry_id')
            ->each(static fn ($lines, $entryId) => DB::table('ledger_entries')
                ->where('id', $entryId)->update(['helper_id' => $lines->first()->driver_id]));

        Schema::dropIfExists('ledger_entry_helpers');
        Schema::dropIfExists('trip_helpers');
    }
};
