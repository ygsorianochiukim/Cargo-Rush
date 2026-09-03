<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `customers` row behind a customer login.
 *
 * Exactly the shape a driver account already has: the login is one record and
 * the business history is another, because the history belongs to the company
 * being hauled for, not to whoever signs in on its behalf. Two people at the
 * same firm can each have an account and see the same deliveries and the same
 * invoices.
 *
 * A customer account without this column set signs in fine and then has
 * nothing to show — every portal endpoint is scoped to it — which is why
 * `cargo:user` asks for the customer when creating one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUlid('customer_id')->nullable()->after('role')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
