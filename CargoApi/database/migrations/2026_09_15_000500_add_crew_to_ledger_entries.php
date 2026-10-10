<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->foreignUlid('driver_id')->nullable()->after('trip_id')
                ->constrained()->nullOnDelete();
            $table->foreignUlid('helper_id')->nullable()->after('driver_id')
                ->constrained('drivers')->nullOnDelete();

            $table->index(['company_id', 'driver_id', 'date']);
            $table->index(['company_id', 'helper_id', 'date']);
        });

        DB::table('ledger_entries')
            ->whereNotNull('trip_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $trip = DB::table('trips')
                        ->select('driver_id', 'helper_id')
                        ->where('id', $row->trip_id)
                        ->first();

                    if ($trip === null) {
                        continue;
                    }

                    DB::table('ledger_entries')
                        ->where('id', $row->id)
                        ->update(['driver_id' => $trip->driver_id, 'helper_id' => $trip->helper_id]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'driver_id', 'date']);
            $table->dropIndex(['company_id', 'helper_id', 'date']);
            $table->dropConstrainedForeignId('driver_id');
            $table->dropConstrainedForeignId('helper_id');
        });
    }
};
