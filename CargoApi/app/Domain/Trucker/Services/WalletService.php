<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Services;

use App\Domain\Billing\Services\TaxService;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\BookingSource;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\Tone;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public const DEFAULT_METHOD = 'bank_transfer';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly TaxService $tax,
    ) {}

    private function splitBase(Trip $trip): int
    {
        return $this->tax->on((int) $trip->price_cents, $trip->customer)->net_cents;
    }

    public function partnerTakeOn(Trip $trip, int $rateBp): int
    {
        $base = $this->splitBase($trip);

        return $base - $this->commissionOn($base, $rateBp);
    }

    public function settleTrip(Trip $trip, ?CarbonInterface $at = null): ?WalletEntry
    {
        $trucker = $trip->trucker;

        if ($trucker === null) {
            return null;
        }

        if ((int) $trip->price_cents <= 0) {
            return null;
        }

        $gross = $this->splitBase($trip);
        $rateBp = $trucker->commissionRateBp();
        $commission = $this->commissionOn($gross, $rateBp);
        $source = $trip->booking_source ?? BookingSource::CargoRush;

        [$kind, $magnitude] = $source->collectedByCarrier()
            ? [WalletEntryKind::Earning, $gross - $commission]
            : [WalletEntryKind::Commission, $commission];

        $entry = $this->post(
            trucker: $trucker,
            kind: $kind,
            magnitude: $magnitude,
            occurredOn: $at ?? now(),
            trip: $trip,
            source: $source,
            grossCents: $gross,
            rateBp: $rateBp,
        );

        $trip->forceFill([
            'commission_bp' => $rateBp,
            'commission_cents' => $commission,
        ])->save();

        $this->tellThePartner($trucker, $entry);

        return $entry;
    }

    public function settleOwnerShare(Trip $trip, ?CarbonInterface $at = null): ?WalletEntry
    {
        $vehicle = $trip->vehicle;

        if ($vehicle === null || ! $vehicle->sharesRevenue()) {
            return null;
        }

        if ((int) $trip->price_cents <= 0) {
            return null;
        }

        $gross = $this->splitBase($trip);
        $rateBp = $vehicle->shareRateBp();
        $owner = $vehicle->ownerPartner;

        if ($owner === null) {
            return null;
        }

        $share = $gross - $this->commissionOn($gross, $rateBp);

        $entry = $this->post(
            trucker: $owner,
            kind: WalletEntryKind::Earning,
            magnitude: $share,
            occurredOn: $at ?? now(),
            trip: $trip,
            source: BookingSource::CargoRush,
            grossCents: $gross,
            rateBp: $rateBp,
        );

        $trip->forceFill([
            'commission_bp' => $rateBp,
            'commission_cents' => $gross - $share,
        ])->save();

        $this->tellThePartner($owner, $entry);

        return $entry;
    }

    public function unsettleTrip(Trip $trip): int
    {
        $entries = WalletEntry::query()
            ->where('trip_id', $trip->getKey())
            ->lockForUpdate()
            ->get();

        abort_if(
            $entries->contains(static fn (WalletEntry $entry): bool => $entry->settled_by !== null),
            422,
            "{$trip->reference} has already been paid out to or remitted by its trucker or truck owner, "
                .'so its price, unit and customer can no longer be changed. Reverse that settlement first.',
        );

        $removed = (int) $entries->sum(static fn (WalletEntry $entry): int => abs((int) $entry->amount_cents));

        $entries->each(static fn (WalletEntry $entry) => $entry->forceDelete());

        $trip->forceFill(['commission_bp' => null, 'commission_cents' => null])->save();

        return $removed;
    }

    /** @param string[] $entryIds */
    public function payOut(
        Trucker $trucker,
        array $entryIds = [],
        ?string $reference = null,
        ?string $note = null,
        ?int $recordedBy = null,
        ?CarbonInterface $occurredOn = null,
        string $method = self::DEFAULT_METHOD,
        bool $cleared = false,
    ): WalletEntry {
        return $this->settle(
            trucker: $trucker,
            settling: WalletEntryKind::Earning,
            with: WalletEntryKind::Payout,
            entryIds: $entryIds,
            nothingOwed: 'There is nothing owed to this trucker to pay out.',
            reference: $reference,
            note: $note,
            recordedBy: $recordedBy,
            occurredOn: $occurredOn,
            method: $method,
            cleared: $cleared,
        );
    }

    public function markLanded(WalletEntry $settlement, ?int $recordedBy = null): WalletEntry
    {
        abort_unless(
            $settlement->kind === WalletEntryKind::Payout
                || $settlement->kind === WalletEntryKind::Remittance,
            422,
            'Only a payout or a remittance can be confirmed as received.',
        );

        abort_unless(
            $settlement->isInFlight(),
            422,
            'That payment has already been confirmed.',
        );

        $settlement->update([
            'status' => StatusValue::Paid->value,
            'recorded_by' => $settlement->recorded_by ?? $recordedBy,
        ]);

        $this->tellThePartnerItLanded($settlement->refresh());

        return $settlement;
    }

    /** @param string[] $entryIds */
    public function remit(
        Trucker $trucker,
        array $entryIds = [],
        ?string $reference = null,
        ?string $note = null,
        ?int $recordedBy = null,
        ?CarbonInterface $occurredOn = null,
        string $method = self::DEFAULT_METHOD,
        bool $cleared = false,
    ): WalletEntry {
        return $this->settle(
            trucker: $trucker,
            settling: WalletEntryKind::Commission,
            with: WalletEntryKind::Remittance,
            entryIds: $entryIds,
            nothingOwed: 'This trucker does not owe anything.',
            reference: $reference,
            note: $note,
            recordedBy: $recordedBy,
            occurredOn: $occurredOn,
            method: $method,
            cleared: $cleared,
        );
    }

    /** @param string[] $entryIds */
    private function settle(
        Trucker $trucker,
        WalletEntryKind $settling,
        WalletEntryKind $with,
        array $entryIds,
        string $nothingOwed,
        ?string $reference,
        ?string $note,
        ?int $recordedBy,
        ?CarbonInterface $occurredOn,
        string $method = self::DEFAULT_METHOD,
        bool $cleared = false,
    ): WalletEntry {
        return DB::transaction(function () use (
            $trucker, $settling, $with, $entryIds, $nothingOwed, $reference, $note,
            $recordedBy, $occurredOn, $method, $cleared
        ): WalletEntry {
            $netting = $entryIds === [] && $with === WalletEntryKind::Payout;

            $outstanding = WalletEntry::query()
                ->where('trucker_id', $trucker->getKey())
                ->landed()
                ->whereNull('settled_by')
                ->when(
                    $netting,
                    static fn ($query) => $query->whereIn('kind', [
                        WalletEntryKind::Earning->value,
                        WalletEntryKind::Commission->value,
                        WalletEntryKind::Adjustment->value,
                    ]),
                    static fn ($query) => $query
                        ->where('kind', $settling->value)
                        ->when($entryIds !== [], static fn ($q) => $q->whereIn('id', $entryIds)),
                )
                ->lockForUpdate()
                ->get();

            if ($netting) {
                $net = (int) $outstanding->sum('amount_cents');

                abort_if($outstanding->isEmpty() || $net <= 0, 422, $nothingOwed);

                $total = $net;
            } else {
                abort_if($outstanding->isEmpty(), 422, $entryIds === []
                    ? $nothingOwed
                    : 'Those runs have already been settled, or are not this trucker\'s.');

                abort_if(
                    $entryIds !== [] && $outstanding->count() !== count(array_unique($entryIds)),
                    422,
                    'Some of those runs have already been settled. Reload and try again.',
                );

                $total = (int) $outstanding->sum(static fn (WalletEntry $entry): int => abs($entry->amount_cents));

                abort_if(
                    $with === WalletEntryKind::Payout
                        && $total > $this->balance($trucker) - $this->inFlightDirected($trucker, $with),
                    422,
                    'That is more than the account stands at once commissions, adjustments and payments already on the way are taken off. Pay everything instead, or pick fewer runs.',
                );
            }

            $settlement = $this->post(
                trucker: $trucker,
                kind: $with,
                magnitude: $total,
                occurredOn: $occurredOn ?? now(),
                reference: $reference,
                note: $note,
                recordedBy: $recordedBy,
                status: $cleared ? StatusValue::Paid : StatusValue::Pending,
                method: $method,
            );

            WalletEntry::query()
                ->whereIn('id', $outstanding->pluck('id'))
                ->update([
                    'settled_by' => $settlement->getKey(),
                    'settled_at' => now(),
                ]);

            return $settlement;
        });
    }

    public function adjust(
        Trucker $trucker,
        int $amountCents,
        string $note,
        ?int $recordedBy = null,
        ?CarbonInterface $occurredOn = null,
    ): WalletEntry {
        abort_if($amountCents === 0, 422, 'An adjustment of nothing changes nothing.');
        abort_if(trim($note) === '', 422, 'Say what this adjustment is for.');

        return $this->post(
            trucker: $trucker,
            kind: WalletEntryKind::Adjustment,
            magnitude: $amountCents,
            occurredOn: $occurredOn ?? now(),
            note: $note,
            recordedBy: $recordedBy,
        );
    }

    /** @return Collection<int, WalletEntry> */
    public function commissionsEarnedBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return WalletEntry::query()
            ->with('trip:id,trucker_id,commission_cents,reference')
            ->whereIn('kind', [WalletEntryKind::Earning->value, WalletEntryKind::Commission->value])
            ->whereNotNull('trip_id')
            ->whereHas('trip', static fn ($trip) => $trip->whereNotNull('trucker_id'))
            ->whereDate('occurred_on', '>=', $from->toDateString())
            ->whereDate('occurred_on', '<=', $to->toDateString())
            ->get();
    }

    public function commissionEarnedBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) $this->commissionsEarnedBetween($from, $to)
            ->sum(static fn (WalletEntry $entry): int => (int) ($entry->trip?->commission_cents ?? 0));
    }

    public function heldForPartnersAsOf(CarbonInterface $asOf): int
    {
        $owners = $this->ownersCostedOnDelivery();

        return (int) WalletEntry::query()
            ->landed()
            ->whereDate('occurred_on', '<=', $asOf->toDateString())
            ->when($owners !== [], static fn ($query) => $query->whereNotIn('trucker_id', $owners))
            ->selectRaw('trucker_id, sum(amount_cents) as balance')
            ->groupBy('trucker_id')
            ->pluck('balance')
            ->filter(static fn ($balance): bool => (int) $balance > 0)
            ->sum();
    }

    public function paidOutBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return abs((int) $this->payoutsLandedBetween($from, $to)->sum('amount_cents'));
    }

    /** @return Collection<int, WalletEntry> */
    public function payoutsLandedBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $alreadyCosted = $this->ownersCostedOnDelivery();

        return WalletEntry::query()
            ->where('kind', WalletEntryKind::Payout->value)
            ->landed()
            ->whereDate('occurred_on', '>=', $from->toDateString())
            ->whereDate('occurred_on', '<=', $to->toDateString())
            ->when(
                $alreadyCosted !== [],
                static fn ($query) => $query->whereNotIn('trucker_id', $alreadyCosted),
            )
            ->get();
    }

    /** @return array<int, string> */
    private function ownersCostedOnDelivery(): array
    {
        return Vehicle::query()
            ->whereNotNull('owner_trucker_id')
            ->get()
            ->filter(static fn (Vehicle $vehicle): bool => $vehicle->sharesRevenue())
            ->pluck('owner_trucker_id')
            ->unique()
            ->values()
            ->all();
    }

    public function balance(Trucker $trucker): int
    {
        return WalletEntry::balanceFor($trucker->getKey());
    }

    private function inFlightDirected(Trucker $trucker, WalletEntryKind $with): int
    {
        return abs((int) WalletEntry::query()
            ->where('trucker_id', $trucker->getKey())
            ->where('kind', $with->value)
            ->inFlight()
            ->sum('amount_cents'));
    }

    public function heldForCostedOwnersAsOf(CarbonInterface $asOf): int
    {
        $owners = $this->ownersCostedOnDelivery();

        if ($owners === []) {
            return 0;
        }

        return (int) WalletEntry::query()
            ->landed()
            ->whereDate('occurred_on', '<=', $asOf->toDateString())
            ->whereIn('trucker_id', $owners)
            ->selectRaw('trucker_id, sum(amount_cents) as balance')
            ->groupBy('trucker_id')
            ->pluck('balance')
            ->filter(static fn ($balance): bool => (int) $balance > 0)
            ->sum();
    }

    /** @return Collection<int, WalletEntry> */
    public function statement(Trucker $trucker, ?string $from = null, ?string $to = null, int $limit = 100): Collection
    {
        return WalletEntry::query()
            ->with(['trip:id,reference,origin,destination', 'settlement:id,reference,occurred_on'])
            ->where('trucker_id', $trucker->getKey())
            ->when($from !== null, static fn ($query) => $query->whereDate('occurred_on', '>=', $from))
            ->when($to !== null, static fn ($query) => $query->whereDate('occurred_on', '<=', $to))
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** @return array<string, mixed> */
    public function summary(Trucker $trucker): array
    {
        $rows = WalletEntry::query()
            ->where('trucker_id', $trucker->getKey())
            ->selectRaw('kind, sum(amount_cents) as total, count(*) as entries')
            ->groupBy('kind')
            ->get()
            ->keyBy(static fn (WalletEntry $entry): string => $entry->kind->value);

        $unsettled = WalletEntry::query()
            ->where('trucker_id', $trucker->getKey())
            ->whereNull('settled_by')
            ->selectRaw('kind, sum(amount_cents) as total, count(*) as entries')
            ->groupBy('kind')
            ->get()
            ->keyBy(static fn (WalletEntry $entry): string => $entry->kind->value);

        $total = static fn (WalletEntryKind $kind): int => (int) ($rows[$kind->value]->total ?? 0);
        $balance = $this->balance($trucker);

        return [
            'balance_cents' => $balance,
            'standing' => match (true) {
                $balance > 0 => 'owed_to_trucker',
                $balance < 0 => 'owed_to_company',
                default => 'settled',
            },
            'earned_cents' => $total(WalletEntryKind::Earning),
            'commission_cents' => abs($total(WalletEntryKind::Commission)),
            'paid_out_cents' => abs($total(WalletEntryKind::Payout)),
            'remitted_cents' => $total(WalletEntryKind::Remittance),
            'adjustments_cents' => $total(WalletEntryKind::Adjustment),
            'trips_settled' => (int) ($rows[WalletEntryKind::Earning->value]->entries ?? 0)
                + (int) ($rows[WalletEntryKind::Commission->value]->entries ?? 0),

            'unpaid_earnings_cents' => (int) ($unsettled[WalletEntryKind::Earning->value]->total ?? 0),
            'unpaid_earnings_count' => (int) ($unsettled[WalletEntryKind::Earning->value]->entries ?? 0),
            'unremitted_commission_cents' => abs(
                (int) ($unsettled[WalletEntryKind::Commission->value]->total ?? 0),
            ),
            'unremitted_commission_count' => (int) ($unsettled[WalletEntryKind::Commission->value]->entries ?? 0),

            'in_flight_cents' => WalletEntry::inFlightFor($trucker->getKey()),

            'payable_cents' => max(0, $balance - $this->inFlightDirected($trucker, WalletEntryKind::Payout)),
        ];
    }

    /** @return Collection<int, WalletEntry> */
    public function outstanding(Trucker $trucker, WalletEntryKind $kind): Collection
    {
        return WalletEntry::query()
            ->with('trip:id,reference,origin,destination')
            ->where('trucker_id', $trucker->getKey())
            ->where('kind', $kind->value)
            ->whereNull('settled_by')
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();
    }

    public function commissionOn(int $grossCents, int $rateBp): int
    {
        return intdiv($grossCents * $rateBp, 10_000);
    }

    private function post(
        Trucker $trucker,
        WalletEntryKind $kind,
        int $magnitude,
        CarbonInterface $occurredOn,
        ?Trip $trip = null,
        ?BookingSource $source = null,
        ?int $grossCents = null,
        ?int $rateBp = null,
        ?string $reference = null,
        ?string $note = null,
        ?int $recordedBy = null,
        StatusValue $status = StatusValue::Paid,
        ?string $method = null,
    ): WalletEntry {
        $sign = $kind->signFor();
        $amount = $sign === 0 ? $magnitude : abs($magnitude) * $sign;

        return DB::transaction(fn (): WalletEntry => WalletEntry::create([
            'trucker_id' => $trucker->getKey(),
            'trip_id' => $trip?->getKey(),
            'kind' => $kind->value,
            'status' => $status->value,
            'method' => $method,
            'source' => $source?->value,
            'amount_cents' => $amount,
            'gross_cents' => $grossCents,
            'rate_bp' => $rateBp,
            'reference' => $reference,
            'note' => $note,
            'occurred_on' => $occurredOn->toDateString(),
            'recorded_by' => $recordedBy,
        ]));
    }

    private function tellThePartner(Trucker $trucker, WalletEntry $entry): void
    {
        if ($trucker->user_id === null) {
            return;
        }

        $credited = $entry->amount_cents > 0;

        $this->notifications->push(
            icon: 'wallet',
            title: $credited ? 'Added to your wallet' : 'Commission charged',
            detail: sprintf(
                '%s · %s',
                $this->peso(abs($entry->amount_cents)),
                $entry->describe(),
            ),
            tone: $credited ? Tone::Success : Tone::Info,
            userId: $trucker->user_id,
        );
    }

    private function tellThePartnerItLanded(WalletEntry $settlement): void
    {
        $trucker = $settlement->trucker;

        if ($trucker?->user_id === null) {
            return;
        }

        $this->notifications->push(
            icon: 'wallet',
            title: 'Payment received',
            detail: sprintf(
                '%s has landed%s',
                $this->peso(abs($settlement->amount_cents)),
                $settlement->reference === null ? '' : " · {$settlement->reference}",
            ),
            tone: Tone::Success,
            userId: $trucker->user_id,
        );
    }

    private function peso(int $cents): string
    {
        return '₱'.number_format($cents / 100, 2);
    }
}
