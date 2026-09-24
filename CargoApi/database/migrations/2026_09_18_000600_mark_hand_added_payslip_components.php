<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which itemised lines the office typed onto a payslip itself.
 *
 * Every row in `pay_run_line_components` used to come from one place: the
 * catalogue, resolved through an assignment when the run was built. Rebuilding
 * a draft could therefore throw them all away and work them out again, which is
 * exactly what `PayrollService::build()` does — it deletes every line wholesale
 * and writes them fresh.
 *
 * That stops being safe the moment somebody can add a deduction to one payslip
 * by hand. A uniform charged to one driver this fortnight has no assignment
 * behind it and nothing to recompute it from, so a rebuild would silently drop
 * it — and an office that loses a ₱2,000 deduction by pressing *Work it out
 * again* stops trusting the screen, which is worse than the missing figure.
 *
 * So a row says where it came from, and `build()` carries the hand-added ones
 * across: it reads them off the old lines before deleting, then folds them back
 * into the same list the catalogue's own components arrive in. They go through
 * the identical arithmetic — the same totals, the same tax base — rather than
 * being stapled on afterwards, which is what keeps a rebuilt payslip adding up.
 *
 * False for everything that already exists, which is true of all of it: nothing
 * could be added by hand before this.
 */
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
