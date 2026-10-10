<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\PayBasis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('pay_basis', 20)
                ->default(PayBasis::Monthly->value)
                ->after('base_salary_cents');

            $table->unsignedBigInteger('daily_rate_cents')->default(0)->after('pay_basis');
        });

        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->string('pay_basis', 20)->default(PayBasis::Monthly->value)->after('position');

            $table->unsignedSmallInteger('days_worked')->default(0)->after('basic_cents');
            $table->unsignedSmallInteger('sheet_days')->default(0)->after('days_worked');
        });
    }

    public function down(): void
    {
        Schema::table('pay_run_lines', function (Blueprint $table): void {
            $table->dropColumn(['pay_basis', 'days_worked', 'sheet_days']);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['pay_basis', 'daily_rate_cents']);
        });
    }
};
