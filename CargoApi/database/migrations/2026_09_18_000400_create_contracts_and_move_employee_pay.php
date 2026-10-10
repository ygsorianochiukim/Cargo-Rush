<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\PayBasis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained()->cascadeOnDelete();

            $table->string('pay_basis', 20)->default(PayBasis::Monthly->value);

            $table->string('tier', 20)->default(EmploymentType::Regular->value);

            $table->unsignedBigInteger('amount_cents')->default(0);

            $table->date('effective_from');

            $table->string('reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'effective_from']);
        });

        $this->backfill();

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['pay_basis', 'base_salary_cents', 'daily_rate_cents']);
        });
    }

    private function backfill(): void
    {
        $now = Carbon::now();

        $employees = DB::table('employees')->select(
            'id', 'company_id', 'employment_type', 'pay_basis',
            'base_salary_cents', 'daily_rate_cents', 'hired_on', 'created_at',
        )->cursor();

        foreach ($employees as $employee) {
            $basis = (string) ($employee->pay_basis ?? PayBasis::Monthly->value);

            $amount = match ($basis) {
                PayBasis::Daily->value => (int) $employee->daily_rate_cents,
                PayBasis::PerTrip->value => 0,
                default => (int) $employee->base_salary_cents,
            };

            $type = EmploymentType::tryFrom((string) ($employee->employment_type ?? ''))
                ?? EmploymentType::Regular;

            DB::table('contracts')->insert([
                'id' => (string) Str::ulid(),
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'pay_basis' => $basis,
                'tier' => $type->tier()->value,
                'amount_cents' => $amount,
                'effective_from' => Carbon::parse(
                    $employee->hired_on ?? $employee->created_at ?? $now,
                )->toDateString(),
                'reason' => 'Opening contract, carried over from the employee record.',
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('pay_basis', 20)->default(PayBasis::Monthly->value)->after('employment_type');
            $table->unsignedBigInteger('base_salary_cents')->default(0)->after('pay_basis');
            $table->unsignedBigInteger('daily_rate_cents')->default(0)->after('base_salary_cents');
        });

        foreach (DB::table('employees')->select('id')->cursor() as $employee) {
            $contract = DB::table('contracts')
                ->where('employee_id', $employee->id)
                ->whereNull('deleted_at')
                ->whereDate('effective_from', '<=', Carbon::now()->toDateString())
                ->orderByDesc('effective_from')
                ->orderByDesc('created_at')
                ->first();

            if ($contract === null) {
                continue;
            }

            $daily = $contract->pay_basis === PayBasis::Daily->value;

            DB::table('employees')->where('id', $employee->id)->update([
                'pay_basis' => $contract->pay_basis,
                'base_salary_cents' => $daily ? 0 : (int) $contract->amount_cents,
                'daily_rate_cents' => $daily ? (int) $contract->amount_cents : 0,
            ]);
        }

        Schema::dropIfExists('contracts');
    }
};
