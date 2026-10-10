<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\BalanceSide;
use Illuminate\Support\Carbon;

class FinancialStatementService
{
    public function __construct(private readonly GeneralLedgerService $ledger) {}

    /** @return array<string, mixed> */
    public function incomeStatement(?Carbon $from = null, ?Carbon $to = null): array
    {
        $income = $this->sections(AccountType::Income, $from, $to);
        $expense = $this->sections(AccountType::Expense, $from, $to);

        $revenue = $this->sum($income);
        $expenses = $this->sum($expense);

        $costLabel = (string) config('cargo.accounting.cost_of_services_group', 'Cost of services');
        $costSection = collect($expense)->firstWhere('group', $costLabel);
        $cost = $costSection === null ? null : (int) $costSection['total_cents'];

        return [
            'range' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],

            'revenue' => [
                'groups' => $income,
                'total_cents' => $revenue,
            ],
            'expenses' => [
                'groups' => $expense,
                'total_cents' => $expenses,
            ],

            'cost_of_services_cents' => $cost,
            'gross_profit_cents' => $cost === null ? null : $revenue - $cost,
            'gross_margin_pct' => $cost === null || $revenue === 0
                ? null
                : round((($revenue - $cost) / $revenue) * 100, 1),

            'net_income_cents' => $revenue - $expenses,
            'net_margin_pct' => $revenue === 0
                ? null
                : round((($revenue - $expenses) / $revenue) * 100, 1),

            'currency' => 'PHP',
        ];
    }

    /** @return array<string, mixed> */
    public function balanceSheet(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        $assets = $this->sections(AccountType::Asset, null, $asOf);
        $liabilities = $this->sections(AccountType::Liability, null, $asOf);
        $equity = $this->sections(AccountType::Equity, null, $asOf);

        $assetTotal = $this->sum($assets);
        $liabilityTotal = $this->sum($liabilities);
        $equityTotal = $this->sum($equity);

        $earnings = $this->incomeStatement(null, $asOf)['net_income_cents'];

        $equityWithEarnings = $equityTotal + $earnings;

        return [
            'as_of' => $asOf->toDateString(),

            'assets' => ['groups' => $assets, 'total_cents' => $assetTotal],
            'liabilities' => ['groups' => $liabilities, 'total_cents' => $liabilityTotal],
            'equity' => [
                'groups' => $equity,
                'posted_total_cents' => $equityTotal,
                'earnings_not_closed_cents' => $earnings,
                'total_cents' => $equityWithEarnings,
            ],

            'liabilities_and_equity_cents' => $liabilityTotal + $equityWithEarnings,
            'balanced' => $assetTotal === $liabilityTotal + $equityWithEarnings,
            'difference_cents' => $assetTotal - ($liabilityTotal + $equityWithEarnings),

            'currency' => 'PHP',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function sections(AccountType $type, ?Carbon $from, ?Carbon $to): array
    {
        $totals = $this->ledger->balances($from, $to);

        $groups = [];

        foreach (Account::query()->where('type', $type->value)->inChartOrder()->get() as $account) {
            $balance = $totals[$account->getKey()] ?? ['debit' => 0, 'credit' => 0];
            $normal = $account->normalBalance();

            $signed = $normal->sign(BalanceSide::Debit) * $balance['debit']
                + $normal->sign(BalanceSide::Credit) * $balance['credit'];

            if ($signed === 0) {
                continue;
            }

            $label = $account->group ?? $type->label();

            $groups[$label] ??= ['group' => $label, 'accounts' => [], 'total_cents' => 0];

            $groups[$label]['accounts'][] = [
                'id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'balance_cents' => $signed,
            ];

            $groups[$label]['total_cents'] += $signed;
        }

        return array_values($groups);
    }

    /** @param array<int, array<string, mixed>> $groups */
    private function sum(array $groups): int
    {
        return (int) collect($groups)->sum('total_cents');
    }
}
