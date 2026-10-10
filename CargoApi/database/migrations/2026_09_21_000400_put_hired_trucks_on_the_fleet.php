<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('arrangement')->default('owned')->after('status');

            $table->unsignedTinyInteger('wheels')->nullable()->after('capacity_kg');

            $table->string('owner_name')->nullable()->after('arrangement');
            $table->string('owner_contact', 40)->nullable()->after('owner_name');

            $table->bigInteger('rent_cents')->nullable()->after('owner_contact');

            $table->unsignedSmallInteger('share_bp')->nullable()->after('rent_cents');

            $table->foreignUlid('owner_trucker_id')->nullable()->after('share_bp')
                ->constrained('truckers')->nullOnDelete();

            $table->index(['company_id', 'arrangement']);
        });

        $rebuildsTables = DB::getDriverName() === 'sqlite';

        if (! $rebuildsTables) {
            Schema::table('truckers', function (Blueprint $table): void {
                $table->dropUnique(['company_id', 'licence_no']);
            });
        }

        Schema::table('truckers', function (Blueprint $table): void {
            $table->string('licence_no')->nullable()->change();
        });

        if (! $rebuildsTables) {
            Schema::table('truckers', function (Blueprint $table): void {
                $table->unique(['company_id', 'licence_no']);
            });
        }

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->bigInteger('owner_share_cents')->default(0)->after('allowance_cents');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropColumn('owner_share_cents');
        });

        $rebuildsTables = DB::getDriverName() === 'sqlite';

        DB::table('truckers')->whereNull('licence_no')->update(['licence_no' => '']);

        if (! $rebuildsTables) {
            Schema::table('truckers', function (Blueprint $table): void {
                $table->dropUnique(['company_id', 'licence_no']);
            });
        }

        Schema::table('truckers', function (Blueprint $table): void {
            $table->string('licence_no')->nullable(false)->change();
        });

        if (! $rebuildsTables) {
            Schema::table('truckers', function (Blueprint $table): void {
                $table->unique(['company_id', 'licence_no']);
            });
        }

        Schema::table('vehicles', function (Blueprint $table) use ($rebuildsTables): void {
            if (! $rebuildsTables) {
                $table->dropForeign(['owner_trucker_id']);
                $table->dropIndex(['company_id', 'arrangement']);

                $table->dropColumn([
                    'arrangement', 'wheels', 'owner_name', 'owner_contact',
                    'rent_cents', 'share_bp', 'owner_trucker_id',
                ]);

                return;
            }

            $table->dropIndex(['company_id', 'arrangement']);
            $table->dropConstrainedForeignId('owner_trucker_id');
            $table->dropColumn([
                'arrangement', 'wheels', 'owner_name', 'owner_contact',
                'rent_cents', 'share_bp',
            ]);
        });
    }
};
