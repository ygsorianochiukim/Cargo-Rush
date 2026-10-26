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
        Schema::table('pay_runs', function (Blueprint $table): void {
            $table->string('scope', 20)->default('office')->after('reference');
            $table->index(['company_id', 'scope']);
        });

        DB::table('pay_runs')
            ->whereExists(static fn ($lines) => $lines
                ->selectRaw('1')
                ->from('pay_run_lines')
                ->whereColumn('pay_run_lines.pay_run_id', 'pay_runs.id')
                ->where('pay_run_lines.crew', true))
            ->whereNotExists(static fn ($lines) => $lines
                ->selectRaw('1')
                ->from('pay_run_lines')
                ->whereColumn('pay_run_lines.pay_run_id', 'pay_runs.id')
                ->where('pay_run_lines.crew', false))
            ->update(['scope' => 'crew']);
    }

    public function down(): void
    {
        Schema::table('pay_runs', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'scope']);
            $table->dropColumn('scope');
        });
    }
};
