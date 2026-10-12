<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sizes = DB::table('truck_categories')
            ->whereNotNull('cbm')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->orderBy('position')
            ->get(['id', 'company_id', 'key', 'name']);

        foreach ($sizes as $size) {
            if (preg_match('/^(\d+)-wheeler$/', (string) $size->key, $m) !== 1
                && preg_match('/^\s*(\d+)\s*-?\s*wheel/i', (string) $size->name, $m) !== 1) {
                continue;
            }

            DB::table('vehicles')
                ->where('company_id', $size->company_id)
                ->where('wheels', (int) $m[1])
                ->whereNull('truck_category_id')
                ->update(['truck_category_id' => $size->id]);
        }
    }

    public function down(): void {}
};
