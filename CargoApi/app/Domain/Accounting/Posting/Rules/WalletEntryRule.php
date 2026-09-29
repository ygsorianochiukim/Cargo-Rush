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

/**
 * A row on a partner's wallet, once it has landed.
 *
 * 2020 Due to truckers is the wallets, netted: what the fleet holds for
 * partners and truck owners. 1160 Due from truckers is the other direction —
 * the cut a partner owes on a run they billed themselves. Between them they are
 * every landed row, and a payout in flight moves neither, exactly as it moves no
 * wallet balance (`WalletEntry::landed()`).
 *
 * **A partner's run the fleet billed** (`earning`, on a partner's trip):
 *
 *     Dr 1140 Unbilled trip income   the run, net of VAT
 *       Cr 2020 Due to truckers        their share — theirs, passing through
 *       Cr 4010 Freight revenue        the fleet's commission, its only income
 *
 * The invoice for the run clears 1140 (`InvoiceRule`). Dated the day it was
 * delivered, as `WalletService::commissionsEarnedBetween()` dates it.
 *
 * **A run the partner billed themselves** (`commission`): Dr 1160 / Cr 4010.
 *
 * **A revenue-share truck owner's earning** posts nothing: the same share is
 * the sheet's Owner's share column, which `SheetDayRule` already credited to
 * 2020 as a cost of the fleet's own run.
 *
 * **Payouts** Dr 2020 / Cr 1020, and a "pay all" that netted commissions off
 * the partner's runs also moves those commissions out of 1160 — they were
 * settled by being deducted, not remitted. **Remittances** Dr 1020 / Cr 1160.
 *
 * **Adjustments** move the wallet against 1170 Trucker adjustments to classify:
 * Finance counts them as nothing, so the books do not guess whether one was an
 * advance, a damage charge or a rounding. The accountant reclassifies.
 */
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

    /** A run's row: the partner's share and the fleet's cut, or nothing. */
    private function fromWork(WalletEntry $entry, int $magnitude): array
    {
        $trip = $entry->trip_id === null ? null : Trip::query()->find($entry->trip_id);

        // Only a partner's own run. An earning on the fleet's trip is a
        // revenue-share owner's, and the sheet has it (see the class note).
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
                // Zero unless the trip's frozen commission and the row disagree.
                ->credit($this->code('unbilled_income'), $magnitude - $commission, 'Difference to the trip’s commission'),
        ];
    }

    /** The commission rows this payout cleared by deducting them. */
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
