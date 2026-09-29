<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Safety, LTO and Warehouse Compliance Checklist, as the driver answered it.
 *
 * Ticked on the phone before the trip — YES, NO or N/A per line, keyed by the
 * line's key in `TripTicketService::CHECKLIST` — and printed on the dispatch
 * sheet with those boxes ticked. Separate from the pre-trip inspection, which
 * is the pass/fail gate on Start; this is the firm's paper form, answered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->json('dispatch_checklist')->nullable();
            $table->string('dispatch_remarks', 500)->nullable();
            $table->timestamp('dispatch_checked_at')->nullable();
            $table->string('dispatch_checked_by', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn(['dispatch_checklist', 'dispatch_remarks', 'dispatch_checked_at', 'dispatch_checked_by']);
        });
    }
};
