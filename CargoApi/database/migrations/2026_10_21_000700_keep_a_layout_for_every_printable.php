<?php

declare(strict_types=1);

use App\Domain\Shared\Support\ChangeLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->json('layout');
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });

        if (! Schema::hasTable(ChangeLog::logTable('print_templates'))) {
            ChangeLog::createTable('print_templates');
        }

        if (Schema::hasColumn('companies', 'invoice_template')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropColumn(['invoice_template', 'invoice_logo_path']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(ChangeLog::logTable('print_templates'));
        Schema::dropIfExists('print_templates');
    }
};
