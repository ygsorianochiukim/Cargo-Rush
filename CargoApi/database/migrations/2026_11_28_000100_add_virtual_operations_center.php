<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->json('virtual_operations_layout')->nullable();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->json('virtual_appearance')->nullable();
        });

        DB::table('nav_items')->updateOrInsert(['key' => 'virtual-operations'], [
            'label' => 'Virtual Operations',
            'icon' => 'shipments',
            'route' => '/virtual-operations',
            'order' => 650,
            'mobile' => false,
            'web' => true,
            'group' => 'People',
            'permission' => 'virtual-operations.view',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('user_modules')->where('nav_key', 'virtual-operations')->delete();
        DB::table('nav_items')->where('key', 'virtual-operations')->delete();

        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('virtual_appearance'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('virtual_operations_layout'));
    }
};
