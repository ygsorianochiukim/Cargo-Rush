<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('contact')->nullable();
            $table->string('address')->nullable();

            $table->string('supplies')->nullable();
            $table->string('note')->nullable();

            $table->string('status')->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'status']);
        });

        Schema::table('maintenance_jobs', function (Blueprint $table): void {
            $table->unsignedBigInteger('cost_cents')->nullable()->after('next_service_km');

            $table->unsignedBigInteger('posted_cents')->default(0)->after('cost_cents');

            $table->date('completed_on')->nullable()->after('posted_cents');

            $table->foreignUlid('supplier_id')->nullable()->after('completed_on')
                ->constrained()->nullOnDelete();

            $table->string('reference')->nullable()->after('supplier_id');
            $table->string('note')->nullable()->after('reference');
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreignUlid('supplier_id')->nullable()->after('driver_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignUlid('supplier_id')->nullable()->after('customer_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_id');
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_id');
        });

        Schema::table('maintenance_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['cost_cents', 'posted_cents', 'completed_on', 'reference', 'note']);
        });

        Schema::dropIfExists('suppliers');
    }
};
