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
    /** @var array<int, array{0: string, 1: string, 2: int}> */
    private const SIZES = [
        ['small-truck', 'Small Truck', 17],
        ['standard-truck', 'Standard Truck', 28],
        ['10-wheeler', '10 Wheeler', 54],
    ];

    private const UNITS = ['vehicles', 'trucker_vehicles', 'trips'];

    public function up(): void
    {
        Schema::create('truck_sizes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('key');
            $table->string('name');
            $table->unsignedSmallInteger('cbm');
            $table->string('status')->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'key']);
            $table->index(['company_id', 'status']);
        });

        if (! Schema::hasTable(ChangeLog::logTable('truck_sizes'))) {
            ChangeLog::createTable('truck_sizes');
        }

        foreach (self::UNITS as $units) {
            Schema::table($units, function (Blueprint $table): void {
                $table->foreignUlid('truck_size_id')->nullable()->after('truck_category_id')
                    ->constrained('truck_sizes')->nullOnDelete();
            });
        }

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $this->moveSizes((string) $companyId);
        }

        Schema::table('truck_categories', function (Blueprint $table): void {
            $table->dropColumn('cbm');
        });
    }

    private function moveSizes(string $companyId): void
    {
        $now = now();

        $sized = DB::table('truck_categories')
            ->where('company_id', $companyId)
            ->whereNotNull('cbm')
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->get(['id', 'key', 'name', 'cbm', 'status']);

        $sizes = [];

        foreach ($sized as $category) {
            $id = strtolower((string) Str::ulid());

            DB::table('truck_sizes')->insert([
                'id' => $id,
                'company_id' => $companyId,
                'key' => $category->key,
                'name' => $category->name,
                'cbm' => $category->cbm,
                'status' => $category->status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $sizes[(int) $category->cbm] = $id;

            foreach (self::UNITS as $units) {
                DB::table($units)
                    ->where('truck_category_id', $category->id)
                    ->update(['truck_size_id' => $id]);
            }

            if (! DB::table('pricing_brackets')->where('truck_category_id', $category->id)->exists()) {
                foreach (self::UNITS as $units) {
                    DB::table($units)
                        ->where('truck_category_id', $category->id)
                        ->update(['truck_category_id' => null]);
                }

                DB::table('truck_categories')->where('id', $category->id)->update([
                    'status' => 'inactive',
                    'deleted_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach (self::SIZES as [$key, $name, $cbm]) {
            $taken = DB::table('truck_sizes')
                ->where('company_id', $companyId)
                ->where(fn ($query) => $query->where('cbm', $cbm)->orWhere('key', $key))
                ->exists();

            if ($taken) {
                continue;
            }

            DB::table('truck_sizes')->insert([
                'id' => strtolower((string) Str::ulid()),
                'company_id' => $companyId,
                'key' => $key,
                'name' => $name,
                'cbm' => $cbm,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('truck_categories', function (Blueprint $table): void {
            $table->unsignedSmallInteger('cbm')->nullable()->after('description');
        });

        foreach (self::UNITS as $units) {
            Schema::table($units, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('truck_size_id');
            });
        }

        Schema::dropIfExists(ChangeLog::logTable('truck_sizes'));
        Schema::dropIfExists('truck_sizes');
    }
};
