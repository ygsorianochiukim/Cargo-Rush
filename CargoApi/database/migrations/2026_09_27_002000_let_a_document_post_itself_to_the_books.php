<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let an operational document keep its journal entry true as it changes.
 *
 * `journal_entries` allowed one posting per source document, ever — the right
 * guarantee while payroll was the only thing that posted, and a run posts once.
 * Everything else now posts itself too (see `AutoPostingService`), and a
 * delivery, an invoice or a day on the sheet is corrected after the fact. A
 * posted entry is immutable, so a correction is a void and a fresh posting —
 * two rows for one document, which the old index refused.
 *
 * The guarantee is kept, one level finer:
 *
 *   `source_rule` — which posting of the document this is. An invoice posts
 *   when it is issued; a supplier bill also when it is marked paid with no
 *   payment behind it. Empty for payroll's single entry.
 *
 *   `source_revision` — which version of it. Zero for payroll, one for a
 *   document's first posting, and one more each time a change supersedes it.
 *
 * Both default to a value rather than to null, deliberately: a null in a unique
 * index is never equal to another null, so defaulting to null would quietly
 * have let payroll post the same run twice again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('source_rule', 40)->default('')->after('source_id');
            $table->unsignedInteger('source_revision')->default(0)->after('source_rule');
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            // The new index first, so a foreign key leaning on the old one is
            // never left without an index under it.
            $table->unique(
                ['company_id', 'source_type', 'source_id', 'source_rule', 'source_revision'],
                'journal_entries_source_revision_unique',
            );
            $table->dropUnique(['company_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->unique(['company_id', 'source_type', 'source_id']);
            $table->dropUnique('journal_entries_source_revision_unique');
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropColumn(['source_rule', 'source_revision']);
        });
    }
};
