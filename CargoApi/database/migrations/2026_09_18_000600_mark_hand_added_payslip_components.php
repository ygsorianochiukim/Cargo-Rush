<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_run_line_components', function (Blueprint $table): void {
            $table->boolean('added_by_hand')->default(false)->after('pay_component_id');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_line_components', function (Blueprint $table): void {
            $table->dropColumn('added_by_hand');
        });
    }
};
