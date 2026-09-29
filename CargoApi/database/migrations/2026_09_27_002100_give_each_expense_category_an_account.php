<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where each kind of expense lands in the chart of accounts.
 *
 * An expense line now posts itself to the journal, and "Toll & Parking" is
 * 5070 while "Office & Admin" is 5210 — the category is the only thing that
 * knows. A code rather than a foreign key to `accounts`, because that is how
 * every other automatic posting names its account (see `cargo.accounting`),
 * and a code survives the chart being re-seeded.
 *
 * Nullable: a category with none posts to `ExpenseCategory::DEFAULT_ACCOUNTS`
 * by its key, and failing that to 5900. The rows that exist are filled from
 * that same list here, so an office can see — and change — where each goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table): void {
            $table->string('account_code', 20)->nullable()->after('icon');
        });

        foreach ($this->defaults() as $key => $code) {
            DB::table('expense_categories')
                ->where('key', $key)
                ->whereNull('account_code')
                ->update(['account_code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table): void {
            $table->dropColumn('account_code');
        });
    }

    /**
     * A copy of the model's list, frozen here: a migration that read the
     * model would change meaning the day the list does.
     *
     * @return array<string, string>
     */
    private function defaults(): array
    {
        return [
            'food' => '5040',
            'lodging' => '5040',
            'toll-parking' => '5070',
            'permits' => '5080',
            'office' => '5210',
            'truck-rental' => '5215',
            'repairs' => '5050',
            'fuel' => '5010',
            'supplies' => '5900',
            'other' => '5900',
        ];
    }
};
