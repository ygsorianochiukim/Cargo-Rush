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
        Schema::table('positions', function (Blueprint $table): void {
            $table->string('pay_basis', 20)
                ->default(PayBasis::Monthly->value)
                ->after('default_role_id');

            $table->unsignedBigInteger('base_salary_cents')->default(0)->after('pay_basis');
            $table->unsignedBigInteger('daily_rate_cents')->default(0)->after('base_salary_cents');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn(['pay_basis', 'base_salary_cents', 'daily_rate_cents']);
        });
    }
};
