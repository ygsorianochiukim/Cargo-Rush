<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table): void {
            $table->string('pod_image_path')->nullable()->after('pod_ref');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table): void {
            $table->dropColumn('pod_image_path');
        });
    }
};
