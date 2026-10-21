<?php

declare(strict_types=1);

namespace App\Domain\Printing\Printables\Concerns;

use App\Domain\Printing\Support\Format;

trait AccountRows
{
    /** @return array<int, array{key: string, label: string, align?: string}> */
    private static function accountColumns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Code'],
            ['key' => 'account', 'label' => 'Account'],
            ['key' => 'amount', 'label' => 'Amount', 'align' => 'right'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, string>>
     */
    private static function accountRows(array $groups, bool $subtotals, string $empty): array
    {
        if ($groups === []) {
            return $empty === '' ? [] : [['code' => '', 'account' => $empty, 'amount' => '']];
        }

        $rows = [];

        foreach ($groups as $group) {
            $rows[] = ['code' => '', 'account' => mb_strtoupper((string) $group['group']), 'amount' => ''];

            foreach ($group['accounts'] as $account) {
                $rows[] = [
                    'code' => (string) $account['code'],
                    'account' => (string) $account['name'],
                    'amount' => Format::money((int) $account['balance_cents']),
                ];
            }

            if ($subtotals) {
                $rows[] = ['code' => '', 'account' => $group['group'].' total', 'amount' => Format::money((int) $group['total_cents'])];
            }
        }

        return $rows;
    }

    /** @return array<string, string> */
    private static function totalRow(string $label, int $cents): array
    {
        return ['code' => '', 'account' => $label, 'amount' => Format::money($cents), 'label' => $label];
    }
}
