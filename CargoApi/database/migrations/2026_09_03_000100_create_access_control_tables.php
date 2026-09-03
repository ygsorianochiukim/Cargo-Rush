<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access control, as rows instead of a hardcoded enum.
 *
 * Until now a role was a PHP enum with its permission list written into a
 * `match` statement, which meant the only way to add a Treasury Officer or a
 * General Manager was a developer and a deployment. Every haulier invents its
 * own shape of office, so that was always going to be the wrong place for it.
 *
 * Three tables, and the split between them is the whole design:
 *
 *   `permissions` is the vocabulary — `finance.view`, `hr.manage`. It is the
 *   one thing here that stays the developer's, because a permission only means
 *   something if code checks for it: inventing `warehouse.view` in the UI would
 *   produce a permission that gates nothing.
 *
 *   `roles` is a named bundle of those, and it is the office's. Add a role,
 *   tick what it reaches, assign people to it.
 *
 *   `positions` is the job title — Driver, Treasury Officer, GM. Kept separate
 *   from roles on purpose: what somebody *is* and what they can *open* are
 *   different questions, and conflating them means you cannot have two drivers
 *   where one also does the books. A position carries a default role, which is
 *   what makes the common case one click.
 *
 * `users.role` stays the string column it already was — it is now a key into
 * `roles` rather than a value constrained by an enum, so no account has to be
 * migrated and the five built-in roles keep working exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // `finance.view` — matched literally by the route middleware and by
            // `nav_items.permission`, so it is not free text.
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            // The module heading it sits under on the permission matrix.
            $table->string('group');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('group');
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // What `users.role` holds. Slug, because it is an identifier.
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            /**
             * A role the app itself depends on.
             *
             * `driver` and `customer` are the two that matter: the handset
             * opens on a different set of tabs for each, and code elsewhere
             * asks whether an account is one. A system role can have its
             * permissions edited but cannot be renamed away or deleted, so the
             * office can tune access without breaking the product.
             */
            $table->boolean('is_system')->default(false);
            /**
             * Holds every permission there is, including ones added later.
             *
             * Without this, adding a permission in a future release would
             * silently take it away from the administrator until somebody
             * noticed and ticked it.
             */
            $table->boolean('all_permissions')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('permission_role', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('permission_id')->constrained()->cascadeOnDelete();

            // One row per pair: ticking a box twice is the same instruction.
            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('positions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            /**
             * The role somebody in this job normally gets.
             *
             * A default, not a rule. The account still names its own role, so a
             * driver who also keeps the books can be given the accountant's
             * access without inventing a job title for it.
             */
            $table->foreignUlid('default_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('employees', function (Blueprint $table): void {
            // Nullable, and the free-text `position` column stays beside it.
            // Every employee already on the roster has a typed job title and
            // no row to point at; forcing one would mean guessing which of
            // them meant the same thing.
            $table->foreignUlid('position_id')->nullable()->after('position')
                ->constrained('positions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('position_id');
        });

        Schema::dropIfExists('positions');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
