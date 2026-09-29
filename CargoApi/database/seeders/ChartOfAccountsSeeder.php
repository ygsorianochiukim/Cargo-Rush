<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Enums\AccountType;
use Illuminate\Database\Seeder;

/**
 * The chart of accounts a haulier can start posting to on day one.
 *
 * Configuration, not data — the same kind of thing as the roles and the expense
 * categories beside it. A company with no chart of accounts cannot write a
 * single journal entry, so an empty `accounts` table is not a business waiting
 * to enter its first record; it is a module that cannot be opened.
 *
 * ## Why this chart, and not a generic one
 *
 * Every account here is one a Philippine trucking firm actually keeps. The
 * numbering is the conventional one — 1000s assets, 2000s liabilities, 3000s
 * equity, 4000s income, 5000s expenses — because an accountant, an auditor and
 * a BIR examiner all expect to find cash in the 1000s, and a clever scheme of
 * our own would cost every one of them time.
 *
 * The expense block is where a fleet's chart earns its keep, and it deliberately
 * mirrors the workbook this system grew out of: diesel, driver salary, helper
 * salary, allowance and maintenance are the five columns of the daily truck
 * sheet, and each has an account of its own here. That is what will let a day's
 * sheet post itself into the journal later without anybody deciding, per row,
 * where it goes.
 *
 * `is_system` on all of them. An office may rename any account, retire any it
 * does not use, and add as many of its own as it likes — but it cannot delete
 * one of these, because code will eventually post to them by code number and an
 * install missing 5010 would fail somewhere far away from whoever tidied it up.
 *
 * ## Idempotent, and safe after a rename
 *
 * Matched on `code`, and only the columns that define the account are written.
 * A firm that renames 5010 from "Fuel and diesel" to "Diesel" keeps that
 * through every future deployment: the seeder does not touch `name`, `group`,
 * `position` or `status` on an account that already exists. Which means adding
 * an account in a later release tops up every company (see `CompanyProvisioner`)
 * without undoing a single thing an office has done to its own chart.
 */
class ChartOfAccountsSeeder extends Seeder
{
    /**
     * code, name, type, group.
     *
     * The order is the order they are printed in, and `position` follows it in
     * tens so an office can slot one of its own between two without
     * renumbering.
     *
     * @var array<int, array{0: string, 1: string, 2: AccountType, 3: string}>
     */
    private const ACCOUNTS = [
        // ---- Assets. What the business owns.
        ['1010', 'Cash on hand', AccountType::Asset, 'Current assets'],
        ['1020', 'Cash in bank', AccountType::Asset, 'Current assets'],
        // What customers owe. The other side of every invoice raised.
        ['1100', 'Accounts receivable', AccountType::Asset, 'Current assets'],
        /**
         * The clearing account between a run's income and its invoice.
         *
         * A delivered run is income the day it is delivered (the daily sheet,
         * or a partner's wallet row); its invoice may follow days later. The
         * income is posted against this account and the invoice clears it, so
         * revenue is recognised once — by the run — and receivables are exactly
         * what Billing says is owed. What is left here is work delivered and
         * not yet invoiced. See `AutoPostingService`.
         */
        ['1140', 'Unbilled trip income', AccountType::Asset, 'Current assets'],
        ['1150', 'Advances to drivers', AccountType::Asset, 'Current assets'],
        // A partner who billed the customer themselves owes the fleet its cut.
        ['1160', 'Due from truckers', AccountType::Asset, 'Current assets'],
        // A correction on a partner's wallet, waiting for the accountant to say
        // what it was — an advance, a damage charge, a rounding.
        ['1170', 'Trucker adjustments to classify', AccountType::Asset, 'Current assets'],
        ['1200', 'Prepaid expenses', AccountType::Asset, 'Current assets'],
        // VAT paid on purchases, claimable against output VAT.
        ['1250', 'Input VAT', AccountType::Asset, 'Current assets'],
        // Tax a customer withheld from what they paid us (BIR 2307) —
        // claimable against the fleet's own income tax.
        ['1260', 'Creditable withholding tax', AccountType::Asset, 'Current assets'],
        /**
         * A supplier's bill, owed and not yet an expense.
         *
         * The period reports count a bill on the day it is paid, and a garage
         * bill for a service job not at all — the job's cost is already on the
         * sheet. The bill is owed from the day it is issued, so it is posted
         * here against accounts payable and moves to an expense as it is paid
         * (or is cleared by the job that carries the same cost).
         */
        ['1270', 'Supplier bills not yet expensed', AccountType::Asset, 'Current assets'],
        ['1300', 'Spare parts and supplies', AccountType::Asset, 'Current assets'],
        ['1500', 'Trucks and trailers', AccountType::Asset, 'Property and equipment'],
        /**
         * A contra-asset: it lives with the assets and carries a credit
         * balance, which is why the type is still `asset` and the figure will
         * simply read negative. Splitting it out as its own type would mean a
         * balance sheet that could not add up its own section.
         */
        ['1510', 'Accumulated depreciation — trucks', AccountType::Asset, 'Property and equipment'],
        ['1600', 'Office equipment', AccountType::Asset, 'Property and equipment'],

        // ---- Liabilities. What it owes.
        ['2010', 'Accounts payable', AccountType::Liability, 'Current liabilities'],
        // Partners' and truck owners' money the fleet holds: their wallets.
        ['2020', 'Due to truckers', AccountType::Liability, 'Current liabilities'],
        ['2100', 'Accrued salaries and wages', AccountType::Liability, 'Current liabilities'],
        // What the daily sheet says the crew earned, until a pay run pays it.
        ['2110', 'Crew pay accrued from the daily sheet', AccountType::Liability, 'Current liabilities'],
        // VAT charged to customers and owed to the BIR.
        ['2150', 'Output VAT', AccountType::Liability, 'Current liabilities'],
        ['2160', 'Withholding tax payable', AccountType::Liability, 'Current liabilities'],
        ['2200', 'SSS, PhilHealth and Pag-IBIG payable', AccountType::Liability, 'Current liabilities'],
        // Money taken for work not yet done. A liability until the load moves.
        ['2250', 'Customer advances', AccountType::Liability, 'Current liabilities'],
        ['2300', 'Loans payable — units', AccountType::Liability, 'Long-term liabilities'],

        // ---- Equity. What is left for the owners.
        ['3010', "Owner's capital", AccountType::Equity, "Owner's equity"],
        ['3020', "Owner's drawings", AccountType::Equity, "Owner's equity"],
        ['3100', 'Retained earnings', AccountType::Equity, "Owner's equity"],

        // ---- Income. What it earns.
        ['4010', 'Freight revenue', AccountType::Income, 'Operating revenue'],
        // Waiting time at a dock, charged on. Its own account because it is the
        // figure an office argues about with a customer.
        ['4020', 'Demurrage and waiting time', AccountType::Income, 'Operating revenue'],
        ['4090', 'Other income', AccountType::Income, 'Other income'],

        /**
         * ---- Expenses. What it costs to earn that.
         *
         * The first five are the daily truck sheet's own columns, in its own
         * order — diesel, driver, helper, allowance, maintenance — so a sheet
         * can post itself here without anybody deciding row by row where it
         * belongs.
         */
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
        // A revenue-share truck's owner's cut of the runs it made.
        ['5110', 'Truck owner share', AccountType::Expense, 'Cost of services'],
        ['5200', 'Office salaries', AccountType::Expense, 'Administrative expenses'],
        ['5210', 'Rent and utilities', AccountType::Expense, 'Administrative expenses'],
        // A truck hired at a flat monthly fee. Cost of services: it hauls.
        ['5215', 'Truck rent', AccountType::Expense, 'Cost of services'],
        ['5220', 'Professional fees', AccountType::Expense, 'Administrative expenses'],
        ['5230', 'Taxes and licences', AccountType::Expense, 'Administrative expenses'],
        ['5300', 'Interest expense', AccountType::Expense, 'Other expenses'],
        ['5900', 'Other operating expenses', AccountType::Expense, 'Other expenses'],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as $index => [$code, $name, $type, $group]) {
            /**
             * `firstOrCreate`, not `updateOrCreate`.
             *
             * The difference is the whole idempotency argument above: an
             * account that already exists is left exactly as the office has it,
             * renames and retirements included. Only a code that is missing is
             * written, which is what tops a company up when a later release
             * adds an account.
             */
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
