<?php

declare(strict_types=1);

use App\Domain\Asset\Services\AssetTagger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $last = DB::table('assets')
                ->where('company_id', $companyId)
                ->where('tag', 'like', AssetTagger::PREFIX.'%')
                ->pluck('tag')
                ->map(static fn (string $tag): int => preg_match('/-(\d+)$/', $tag, $m) ? (int) $m[1] : 0)
                ->max() ?? 0;

            $untagged = DB::table('assets')
                ->where('company_id', $companyId)
                ->where(fn ($q) => $q->whereNull('tag')->orWhere('tag', ''))
                ->orderBy('created_at')
                ->get(['id', 'purchased_on', 'created_at']);

            foreach ($untagged as $asset) {
                DB::table('assets')->where('id', $asset->id)->update([
                    'tag' => AssetTagger::format($asset->purchased_on ?? $asset->created_at, ++$last),
                ]);
            }
        }
    }

    public function down(): void {}
};
