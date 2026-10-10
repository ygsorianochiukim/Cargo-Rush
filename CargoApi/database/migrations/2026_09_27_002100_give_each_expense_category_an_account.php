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

    /** @return array<string, string> */
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
