<?php

declare(strict_types=1);

use App\Domain\Trip\Models\Trip;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->unsignedInteger('reference_seq')->nullable()->after('reference');
            $table->string('legacy_reference')->nullable()->after('reference_seq');

            $table->unique(['company_id', 'reference_seq']);
            $table->index('legacy_reference');
        });

        DB::transaction(function (): void {
            $series = [];

            foreach (DB::table('trips')->orderBy('created_at')->orderBy('id')->get(['id', 'company_id', 'reference', 'created_at']) as $trip) {
                $company = (string) $trip->company_id;
                $series[$company] = ($series[$company] ?? 0) + 1;

                $reference = sprintf(
                    'CTS-%s-%05d',
                    Carbon::parse($trip->created_at ?? now(), 'UTC')->setTimezone('Asia/Manila')->format('mdy'),
                    $series[$company],
                );

                DB::table('trips')->where('id', $trip->id)->update([
                    'reference' => $reference,
                    'reference_seq' => $series[$company],
                    'legacy_reference' => $trip->reference,
                ]);

                foreach (DB::table('expenses')->where('trip_id', $trip->id)->where('reference', $trip->reference)->get(['id', 'note']) as $expense) {
                    DB::table('expenses')->where('id', $expense->id)->update([
                        'reference' => $reference,
                        'note' => $expense->note === null ? null : str_replace((string) $trip->reference, $reference, $expense->note),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        DB::table('trips')->whereNotNull('legacy_reference')->update(['reference' => DB::raw('legacy_reference')]);

        Schema::table('trips', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'reference_seq']);
            $table->dropIndex(['legacy_reference']);
            $table->dropColumn(['reference_seq', 'legacy_reference']);
        });
    }
};
