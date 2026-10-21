<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: int}> */
    private const CATEGORIES = [
        ['electricity', 'Electricity', 'Monthly electric bill for the office, yard or garage.', 'gauge', 81],
        ['water', 'Water', 'Water district bill and delivered drinking water.', 'gauge', 82],
        ['internet-phone', 'Internet & Phone', 'Internet, landline and mobile load or postpaid plans.', 'bell', 83],
        ['rent', 'Rent', 'Office, yard or garage rent.', 'dashboard', 84],
    ];

    public function up(): void
    {
        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (self::CATEGORIES as [$key, $name, $description, $icon, $position]) {
                $exists = DB::table('expense_categories')
                    ->where('company_id', $companyId)
                    ->where('key', $key)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('expense_categories')->insert([
                    'id' => strtolower((string) Str::ulid()),
                    'company_id' => $companyId,
                    'key' => $key,
                    'name' => $name,
                    'description' => $description,
                    'icon' => $icon,
                    'account_code' => '5210',
                    'position' => $position,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $utilities = DB::table('supplier_categories')
                ->where('company_id', $companyId)
                ->whereRaw('UPPER(name) = ?', ['UTILITIES'])
                ->exists();

            if (! $utilities) {
                DB::table('supplier_categories')->insert([
                    'id' => strtolower((string) Str::ulid()),
                    'company_id' => $companyId,
                    'name' => 'UTILITIES',
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void {}
};
