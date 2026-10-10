<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Rules;

use App\Domain\Accounting\Posting\PostingRule;
use App\Domain\Shared\Enums\JournalCategory;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\WalletEntry;
use Illuminate\Database\Eloquent\Model;

class WalletEntryRule extends PostingRule
{
    public function postings(Model $source): array
    {
        /** @var WalletEntry $entry */
        $entry = $source;

        if ($entry->status !== StatusValue::Paid || $entry->occurred_on === null) {
            return [];
        }

        $magnitude = abs((int) $entry->amount_cents);
        $dueTo = $this->code('due_to_truckers');
        $dueFrom = $this->code('due_from_truckers');
        $cash = $this->code('cash');

        return match ($entry->kind) {
            WalletEntryKind::Earning, WalletEntryKind::Commission => $this->fromWork($entry, $magnitude),

            WalletEntryKind::Payout => [
                $this->posting('wallet', $entry->occurred_on, JournalCategory::Disbursement, $this->memo($entry, 'Paid out to'))
                    ->debit($dueTo, $magnitude + $this->commissionsNetted($entry), 'Paid out')
                    ->credit($dueFrom, $this->commissionsNetted($entry), 'Commission deducted from the payout')
                    ->credit($cash, $magnitude, 'Paid out'),
            ],

            WalletEntryKind::Remittance => [
                $this->posting('wallet', $entry->occurred_on, JournalCategory::Collection, $this->memo($entry, 'Remitted by'))
                    ->debit($cash, $magnitude, 'Commission remitted')
                    ->credit($dueFrom, $magnitude, 'Commission remitted'),
            ],

            WalletEntryKind::Adjustment => [
                $this->posting('wallet', $entry->occurred_on, JournalCategory::Adjustment, $this->memo($entry, 'Wallet adjustment ·'))
                    ->debit($this->code('trucker_adjustments'), (int) $entry->amount_cents, $entry->note)
                    ->credit($dueTo, (int) $entry->amount_cents, $entry->note),
            ],
        };
    }

    public function label(Model $source): string
    {
        /** @var WalletEntry $source */
        return 'wallet entry '.$source->describe();
    }

    private function fromWork(WalletEntry $entry, int $magnitude): array
    {
        $trip = $entry->trip_id === null ? null : Trip::query()->find($entry->trip_id);

        if ($trip === null || $trip->trucker_id === null) {
            return [];
        }

        $commission = (int) ($trip->commission_cents ?? 0);

        $posting = $this->posting('wallet', $entry->occurred_on, JournalCategory::Operations, sprintf(
            '%s · %s',
            $entry->kind === WalletEntryKind::Earning ? 'Partner run, billed by the fleet' : 'Partner run, billed by the partner',
            $trip->reference ?? $trip->getKey(),
        ))->about(['trip_id' => $trip->getKey(), 'customer_id' => $trip->customer_id]);

        if ($entry->kind === WalletEntryKind::Earning) {
            return [
                $posting->debit($this->code('unbilled_income'), $magnitude + $commission, 'The run, to invoice')
                    ->credit($this->code('due_to_truckers'), $magnitude, 'The partner’s share')
                    ->credit($this->code('freight_revenue'), $commission, 'Commission'),
            ];
        }

        return [
            $posting->debit($this->code('due_from_truckers'), $magnitude, 'Commission owed by the partner')
                ->credit($this->code('freight_revenue'), $commission, 'Commission')
                ->credit($this->code('unbilled_income'), $magnitude - $commission, 'Difference to the trip’s commission'),
        ];
    }

    private function commissionsNetted(WalletEntry $payout): int
    {
        return abs((int) WalletEntry::query()
            ->where('settled_by', $payout->getKey())
            ->where('kind', WalletEntryKind::Commission->value)
            ->sum('amount_cents'));
    }

    private function memo(WalletEntry $entry, string $what): string
    {
        $name = $entry->trucker()->withTrashed()->value('name') ?? 'a trucker';

        return trim(sprintf('%s %s %s', $what, $name, $entry->reference ?? ''));
    }
}
