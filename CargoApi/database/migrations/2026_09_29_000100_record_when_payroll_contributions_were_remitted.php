<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a paid run's contributions and tax actually reached the agencies.
 *
 * Until now there was no record of it, so Payables presumed the money owed to
 * SSS, PhilHealth, Pag-IBIG and the BIR until the end of the month after the
 * period closed. An office that remits early read a debt for weeks after it had
 * been paid. The date, the office's reference and the journal entry that paid
 * it are recorded here; a run with no date keeps the presumption.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_runs', function (Blueprint $table): void {
            $table->date('remitted_on')->nullable()->after('paid_at');
            $table->string('remittance_reference', 120)->nullable()->after('remitted_on');
            $table->ulid('remittance_entry_id')->nullable()->after('remittance_reference');
        });
    }

    public function down(): void
    {
        Schema::table('pay_runs', function (Blueprint $table): void {
            $table->dropColumn(['remitted_on', 'remittance_reference', 'remittance_entry_id']);
        });
    }
};
