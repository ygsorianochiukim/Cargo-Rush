<?php

declare(strict_types=1);

use App\Domain\Shared\Support\ChangeLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_driver_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('driver_id')->constrained()->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'from_date', 'to_date']);
            $table->index(['vehicle_id', 'to_date']);
        });

        if (! Schema::hasTable(ChangeLog::logTable('vehicle_driver_assignments'))) {
            ChangeLog::createTable('vehicle_driver_assignments');
        }

        $now = now();

        $rows = DB::table('vehicles')
            ->whereNull('deleted_at')
            ->whereNotNull('driver_id')
            ->get(['id', 'company_id', 'driver_id', 'updated_at', 'created_at'])
            ->map(static fn (object $vehicle): array => [
                'id' => (string) Str::ulid(),
                'company_id' => $vehicle->company_id,
                'vehicle_id' => $vehicle->id,
                'driver_id' => $vehicle->driver_id,
                'from_date' => substr((string) ($vehicle->updated_at ?? $vehicle->created_at ?? $now), 0, 10),
                'to_date' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('vehicle_driver_assignments')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(ChangeLog::logTable('vehicle_driver_assignments'));
        Schema::dropIfExists('vehicle_driver_assignments');
    }
};
