<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $existing = DB::table('truck_categories')
                ->where('company_id', $companyId)
                ->where('key', '10-wheeler')
                ->first(['id', 'cbm', 'deleted_at']);

            if ($existing === null) {
                $id = strtolower((string) Str::ulid());

                DB::table('truck_categories')->insert([
                    'id' => $id,
                    'company_id' => $companyId,
                    'key' => '10-wheeler',
                    'name' => '10 Wheeler',
                    'description' => '54 cbm. Priced off the 28 cbm fleet line, divided by 28 and times 54.',
                    'cbm' => 54,
                    'position' => 102,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $id = $existing->id;

                if ($existing->cbm === null || $existing->deleted_at !== null) {
                    DB::table('truck_categories')->where('id', $id)->update([
                        'cbm' => $existing->cbm ?? 54,
                        'status' => 'active',
                        'deleted_at' => null,
                        'updated_at' => $now,
                    ]);
                }
            }

            DB::table('vehicles')
                ->where('company_id', $companyId)
                ->where('wheels', 10)
                ->whereNull('truck_category_id')
                ->update(['truck_category_id' => $id]);
        }
    }

    public function down(): void {}
};
