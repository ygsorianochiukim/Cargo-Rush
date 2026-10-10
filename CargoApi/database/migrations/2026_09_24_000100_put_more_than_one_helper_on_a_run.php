<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
