<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support and identity — notifications, the inspection modules, and the nav
 * that drives both shells.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The in-app feed. Deliberately not Laravel's own `notifications`
        // table: this is a domain resource with an icon name and a tone, and
        // the clients render it straight from the section 7.1 envelope.
        Schema::create('notification_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Null means fleet-wide — everyone sees it.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            // A name from the shared icon set, never a URL (DESIGN.md 7.3).
            $table->string('icon');
            $table->string('title');
            $table->string('detail');
            $table->string('tone')->default('info');
            $table->boolean('read')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'read']);
        });

        // Pre-trip inspection, captured at the vehicle (DESIGN.md 5.4).
        Schema::create('inspections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('driver_id')->nullable()->constrained()->nullOnDelete();
            // { "tires": true, "oil": false, ... } keyed by checklist item.
            $table->json('results');
            $table->boolean('good_to_go')->default(false);
            $table->string('notes')->nullable();
            $table->timestamp('inspected_at');
            $table->timestamps();
        });

        Schema::create('maintenance_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->date('due_at');
            $table->unsignedInteger('next_service_km')->default(0);
            $table->string('status')->default('scheduled');
            $table->timestamps();
            $table->softDeletes();
        });

        // The sidebar and the tab bar are rendered from this table, filtered by
        // permission. Neither client keeps a hardcoded nav list (DESIGN.md 7.3).
        Schema::create('nav_items', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('icon');
            $table->string('route');
            $table->unsignedSmallInteger('order')->default(0);
            $table->boolean('mobile')->default(false);
            $table->boolean('web')->default(true);
            // Sidebar section heading. Items sharing a group render together.
            $table->string('group');
            // Null means everyone; otherwise the permission the user must hold.
            $table->string('permission')->nullable();
            // Which live count feeds the badge, e.g. `trips.pending`. Null: none.
            $table->string('badge_source')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nav_items');
        Schema::dropIfExists('maintenance_jobs');
        Schema::dropIfExists('inspections');
        Schema::dropIfExists('notification_items');
    }
};
