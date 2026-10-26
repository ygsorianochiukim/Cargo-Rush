<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('expenses', 'asset_id')) {
            Schema::table('expenses', function (Blueprint $table): void {
                $table->foreignUlid('asset_id')->nullable()->after('supplier_id')
                    ->constrained('assets')->nullOnDelete();
            });
        }

        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $category = DB::table('expense_categories')
                ->where('company_id', $companyId)
                ->where('key', 'assets')
                ->value('id');

            if ($category === null) {
                $category = strtolower((string) Str::ulid());

                DB::table('expense_categories')->insert([
                    'id' => $category,
                    'company_id' => $companyId,
                    'key' => 'assets',
                    'name' => 'Assets & Equipment',
                    'description' => 'Laptops, radios, tools and furniture entered in the asset register.',
                    'icon' => 'clipboard',
                    'account_code' => '1600',
                    'position' => 85,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $assets = DB::table('assets as a')
                ->leftJoin('suppliers as s', 's.id', '=', 'a.supplier_id')
                ->where('a.company_id', $companyId)
                ->whereNull('a.deleted_at')
                ->where('a.cost_cents', '>', 0)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')
                    ->from('expenses as e')
                    ->whereColumn('e.asset_id', 'a.id'))
                ->get(['a.id', 'a.tag', 'a.name', 'a.purchased_on', 'a.created_at', 'a.cost_cents', 'a.supplier_id', 's.name as supplier_name']);

            foreach ($assets as $asset) {
                DB::table('expenses')->insert([
                    'id' => strtolower((string) Str::ulid()),
                    'company_id' => $companyId,
                    'category_id' => $category,
                    'supplier_id' => $asset->supplier_id,
                    'asset_id' => $asset->id,
                    'date' => $asset->purchased_on ?? substr((string) $asset->created_at, 0, 10),
                    'amount_cents' => $asset->cost_cents,
                    'currency' => 'PHP',
                    'payee' => $asset->supplier_name,
                    'reference' => $asset->tag,
                    'note' => sprintf('Purchase of %s', $asset->name),
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('asset_id');
        });
    }
};
