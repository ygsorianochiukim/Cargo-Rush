<?php

declare(strict_types=1);

use Database\Seeders\AssetCategorySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (AssetCategorySeeder::NAMES as $name) {
                $exists = DB::table('asset_categories')
                    ->where('company_id', $companyId)
                    ->whereRaw('UPPER(name) = ?', [$name])
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('asset_categories')->insert([
                    'id' => strtolower((string) Str::ulid()),
                    'company_id' => $companyId,
                    'name' => $name,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void {}
};
