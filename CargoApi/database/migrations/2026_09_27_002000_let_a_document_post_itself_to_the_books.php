<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('source_rule', 40)->default('')->after('source_id');
            $table->unsignedInteger('source_revision')->default(0)->after('source_rule');
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
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
