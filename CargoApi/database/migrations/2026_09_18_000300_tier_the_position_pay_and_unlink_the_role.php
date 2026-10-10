<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\Role as SystemRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->boolean('drives')->default(false)->after('description');

            $table->unsignedBigInteger('trainee_amount_cents')->default(0)->after('pay_basis');
            $table->unsignedBigInteger('probationary_amount_cents')->default(0)->after('trainee_amount_cents');
            $table->unsignedBigInteger('regular_amount_cents')->default(0)->after('probationary_amount_cents');
        });

        $driverRoleIds = DB::table('roles')
            ->where('key', SystemRole::Driver->value)
            ->pluck('id')
            ->all();

        if ($driverRoleIds !== []) {
            DB::table('positions')
                ->whereIn('default_role_id', $driverRoleIds)
                ->update(['drives' => true]);
        }

        foreach (DB::table('positions')->select('id', 'pay_basis', 'base_salary_cents', 'daily_rate_cents')->cursor() as $position) {
            $amount = $position->pay_basis === 'daily'
                ? (int) $position->daily_rate_cents
                : (int) $position->base_salary_cents;

            if ($amount <= 0) {
                continue;
            }

            DB::table('positions')->where('id', $position->id)->update([
                'trainee_amount_cents' => $amount,
                'probationary_amount_cents' => $amount,
                'regular_amount_cents' => $amount,
            ]);
        }

        Schema::table('positions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_role_id');
        });

        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn(['base_salary_cents', 'daily_rate_cents']);
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->foreignUlid('default_role_id')->nullable()->after('description')
                ->constrained('roles')->nullOnDelete();
            $table->unsignedBigInteger('base_salary_cents')->default(0)->after('pay_basis');
            $table->unsignedBigInteger('daily_rate_cents')->default(0)->after('base_salary_cents');
        });

        foreach (DB::table('positions')->select('id', 'pay_basis', 'regular_amount_cents')->cursor() as $position) {
            DB::table('positions')->where('id', $position->id)->update(
                $position->pay_basis === 'daily'
                    ? ['daily_rate_cents' => (int) $position->regular_amount_cents]
                    : ['base_salary_cents' => (int) $position->regular_amount_cents],
            );
        }

        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn(['drives', 'trainee_amount_cents', 'probationary_amount_cents', 'regular_amount_cents']);
        });
    }
};
