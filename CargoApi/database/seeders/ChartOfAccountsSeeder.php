<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Enums\AccountType;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    /** @var array<int, array{0: string, 1: string, 2: AccountType, 3: string}> */
    private const ACCOUNTS = [
        ['1010', 'Cash on hand', AccountType::Asset, 'Current assets'],
        ['1020', 'Cash in bank', AccountType::Asset, 'Current assets'],
        ['1100', 'Accounts receivable', AccountType::Asset, 'Current assets'],
        ['1140', 'Unbilled trip income', AccountType::Asset, 'Current assets'],
        ['1150', 'Advances to drivers', AccountType::Asset, 'Current assets'],
        ['1160', 'Due from truckers', AccountType::Asset, 'Current assets'],
        ['1170', 'Trucker adjustments to classify', AccountType::Asset, 'Current assets'],
        ['1200', 'Prepaid expenses', AccountType::Asset, 'Current assets'],
        ['1250', 'Input VAT', AccountType::Asset, 'Current assets'],
        ['1260', 'Creditable withholding tax', AccountType::Asset, 'Current assets'],
        ['1270', 'Supplier bills not yet expensed', AccountType::Asset, 'Current assets'],
        ['1300', 'Spare parts and supplies', AccountType::Asset, 'Current assets'],
        ['1500', 'Trucks and trailers', AccountType::Asset, 'Property and equipment'],
        ['1510', 'Accumulated depreciation — trucks', AccountType::Asset, 'Property and equipment'],
        ['1600', 'Office equipment', AccountType::Asset, 'Property and equipment'],

        ['2010', 'Accounts payable', AccountType::Liability, 'Current liabilities'],
        ['2020', 'Due to truckers', AccountType::Liability, 'Current liabilities'],
        ['2100', 'Accrued salaries and wages', AccountType::Liability, 'Current liabilities'],
        ['2110', 'Crew pay accrued from the daily sheet', AccountType::Liability, 'Current liabilities'],
        ['2150', 'Output VAT', AccountType::Liability, 'Current liabilities'],
        ['2160', 'Withholding tax payable', AccountType::Liability, 'Current liabilities'],
        ['2200', 'SSS, PhilHealth and Pag-IBIG payable', AccountType::Liability, 'Current liabilities'],
        ['2250', 'Customer advances', AccountType::Liability, 'Current liabilities'],
        ['2300', 'Loans payable — units', AccountType::Liability, 'Long-term liabilities'],

        ['3010', "Owner's capital", AccountType::Equity, "Owner's equity"],
        ['3020', "Owner's drawings", AccountType::Equity, "Owner's equity"],
        ['3100', 'Retained earnings', AccountType::Equity, "Owner's equity"],

        ['4010', 'Freight revenue', AccountType::Income, 'Operating revenue'],
        ['4020', 'Demurrage and waiting time', AccountType::Income, 'Operating revenue'],
        ['4090', 'Other income', AccountType::Income, 'Other income'],

        ['5010', 'Fuel and diesel', AccountType::Expense, 'Cost of services'],
        ['5020', 'Driver salaries', AccountType::Expense, 'Cost of services'],
        ['5030', 'Helper salaries', AccountType::Expense, 'Cost of services'],
        ['5040', 'Driver allowances', AccountType::Expense, 'Cost of services'],
        ['5050', 'Repairs and maintenance', AccountType::Expense, 'Cost of services'],
        ['5060', 'Tyres', AccountType::Expense, 'Cost of services'],
        ['5070', 'Tolls and terminal fees', AccountType::Expense, 'Cost of services'],
        ['5080', 'Registration and permits', AccountType::Expense, 'Cost of services'],
        ['5090', 'Insurance', AccountType::Expense, 'Cost of services'],
        ['5100', 'Depreciation — trucks', AccountType::Expense, 'Cost of services'],
        ['5110', 'Truck owner share', AccountType::Expense, 'Cost of services'],
        ['5200', 'Office salaries', AccountType::Expense, 'Administrative expenses'],
        ['5210', 'Rent and utilities', AccountType::Expense, 'Administrative expenses'],
        ['5215', 'Truck rent', AccountType::Expense, 'Cost of services'],
        ['5220', 'Professional fees', AccountType::Expense, 'Administrative expenses'],
        ['5230', 'Taxes and licences', AccountType::Expense, 'Administrative expenses'],
        ['5300', 'Interest expense', AccountType::Expense, 'Other expenses'],
        ['5900', 'Other operating expenses', AccountType::Expense, 'Other expenses'],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as $index => [$code, $name, $type, $group]) {
            Account::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type->value,
                    'group' => $group,
                    'position' => ($index + 1) * 10,
                    'is_system' => true,
                ],
            );
        }

        $this->command?->info(sprintf('Chart of accounts: %d accounts in place.', count(self::ACCOUNTS)));
    }
}
