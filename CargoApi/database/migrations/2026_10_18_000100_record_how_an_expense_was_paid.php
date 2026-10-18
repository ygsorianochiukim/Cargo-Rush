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
        Schema::create('payment_sources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('kind', 20);
            $table->string('provider')->nullable();
            $table->string('last_four', 4)->nullable();
            $table->string('note')->nullable();
            $table->string('status')->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'status']);
        });

        if (! Schema::hasTable(ChangeLog::logTable('payment_sources'))) {
            ChangeLog::createTable('payment_sources');
        }

        Schema::table('expenses', function (Blueprint $table): void {
            $table->boolean('is_vatable')->default(false)->after('currency');
            $table->foreignUlid('payment_source_id')->nullable()->after('supplier_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('fuel_records', function (Blueprint $table): void {
            $table->foreignUlid('payment_source_id')->nullable()->after('driver_id')
                ->constrained()->nullOnDelete();
        });

        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $exists = DB::table('payment_sources')
                ->where('company_id', $companyId)
                ->where('kind', 'cash')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('payment_sources')->insert([
                'id' => strtolower((string) Str::ulid()),
                'company_id' => $companyId,
                'name' => 'Cash',
                'kind' => 'cash',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('fuel_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_source_id');
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_source_id');
            $table->dropColumn('is_vatable');
        });

        Schema::dropIfExists(ChangeLog::logTable('payment_sources'));
        Schema::dropIfExists('payment_sources');
    }
};
