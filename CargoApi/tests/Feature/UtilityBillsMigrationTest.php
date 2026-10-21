<?php

declare(strict_types=1);

use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Supplier\Models\SupplierCategory;
use Illuminate\Support\Facades\DB;

it('gives an existing company the utility categories once', function (): void {
    DB::table('expense_categories')->delete();
    DB::table('supplier_categories')->delete();
    SupplierCategory::create(['name' => 'Utilities']);

    $migration = require database_path('migrations/2026_10_21_000200_file_utility_bills_as_expenses.php');
    $migration->up();
    $migration->up();

    $keys = ExpenseCategory::orderBy('position')->pluck('key')->all();

    expect($keys)->toBe(['electricity', 'water', 'internet-phone', 'rent'])
        ->and(ExpenseCategory::where('key', 'water')->value('account_code'))->toBe('5210')
        ->and(ExpenseCategory::where('key', 'water')->value('company_id'))->toBe($this->company->id)
        ->and(SupplierCategory::count())->toBe(1);
});
