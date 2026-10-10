<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\DeductionSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('payroll_deduct_on', 10)
                ->default(DeductionSchedule::Split->value)
                ->after('vat_rate_bp');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('payroll_deduct_on');
        });
    }
};
