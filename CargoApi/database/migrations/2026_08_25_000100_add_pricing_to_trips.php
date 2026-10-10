<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_cents')->default(0)->after('handling');
            $table->string('currency', 3)->default('PHP')->after('price_cents');
            $table->timestamp('billed_at')->nullable()->after('eta');
            $table->foreignId('requested_by')->nullable()->after('billed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['price_cents', 'currency', 'billed_at']);
        });
    }
};
