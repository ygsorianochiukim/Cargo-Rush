<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_jobs', function (Blueprint $table): void {
            $table->foreignUlid('invoice_id')->nullable()->after('supplier_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('fuel_records', function (Blueprint $table): void {
            $table->unsignedBigInteger('posted_cents')->default(0)->after('amount_cents');
            $table->foreignUlid('posted_truck_id')->nullable()->after('posted_cents')
                ->constrained('trucks')->nullOnDelete();
            $table->date('posted_on')->nullable()->after('posted_truck_id');
        });
    }

    public function down(): void
    {
        Schema::table('fuel_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('posted_truck_id');
            $table->dropColumn(['posted_cents', 'posted_on']);
        });

        Schema::table('maintenance_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
