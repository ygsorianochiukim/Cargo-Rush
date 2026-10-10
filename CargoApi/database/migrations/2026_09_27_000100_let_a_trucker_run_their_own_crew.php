<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('truckers', function (Blueprint $table): void {
            $table->string('business_name', 160)->nullable()->after('name');

            $table->string('licence_no')->nullable()->change();
        });

        Schema::create('trucker_drivers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('trucker_id')->constrained()->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->string('licence_no');
            $table->date('licence_expiry')->nullable();

            $table->string('status')->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['trucker_id', 'licence_no']);
            $table->index(['trucker_id', 'status']);
        });

        Schema::table('trips', function (Blueprint $table): void {
            $table->foreignUlid('trucker_driver_id')->nullable()->after('trucker_vehicle_id')
                ->constrained('trucker_drivers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('trucker_driver_id');
        });

        Schema::dropIfExists('trucker_drivers');

        Schema::table('truckers', function (Blueprint $table): void {
            $table->dropColumn('business_name');
        });
    }
};
