<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\DTO\AccountData;
use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\StatusValue;
use Illuminate\Support\Collection;

class AccountService
{
    /** @return Collection<int, Account> */
    public function all(bool $activeOnly = false): Collection
    {
        return Account::query()
            ->when($activeOnly, fn ($query) => $query->active())
            ->inChartOrder()
            ->get();
    }

    public function create(AccountData $data): Account
    {
        return Account::create($data->persistable())->refresh();
    }

    public function update(Account $account, AccountData $data): Account
    {
        if ($data->type !== null && $data->type !== $account->type && $account->hasPostings()) {
            abort(422, sprintf(
                '%s already has postings against it, so it has to stay %s. '
                .'Retire it and open a new account of the right kind — the old postings keep their meaning.',
                $account->label(),
                $account->type->label(),
            ));
        }

        $account->update($data->persistable());

        return $account->refresh();
    }

    public function delete(Account $account): bool
    {
        if ($account->is_system || $account->hasPostings()) {
            $account->update(['status' => StatusValue::Inactive->value]);

            return false;
        }

        $account->delete();

        return true;
    }

    /** @return array<int, array<string, mixed>> */
    public function types(): array
    {
        return array_map(static fn (AccountType $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'normal_balance' => $type->normalBalance()->value,
            'permanent' => $type->isPermanent(),
            'position' => $type->position(),
        ], AccountType::cases());
    }
}
