<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of place a supplier is — a garage, a mall, a food stall.
 *
 * The firm's own list, kept on Access Control, so every shop picker can say
 * which kind each name is and sort them together instead of one long run of
 * names. A category with suppliers under it is retired rather than deleted,
 * the way an expense category is, so an old supplier never loses its label.
 *
 * Nullable on the supplier: every shop registered before this has none, and a
 * picker shows it plainly rather than guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->foreignUlid('category_id')->nullable()->after('company_id')
                ->constrained('supplier_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::dropIfExists('supplier_categories');
    }
};
