<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('brand');
            $table->timestamps();
        });

        Schema::create('marketing_drafts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('topic', 500);
            $table->json('content');
            $table->json('trend')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_drafts');
        Schema::dropIfExists('marketing_profiles');
    }
};
