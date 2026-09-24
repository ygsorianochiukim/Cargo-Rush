<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Services;

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

/**
 * A partner's money: what a run earned them, what it cost them, and settling up.
 *
 * ## The split, in one paragraph
 *
 * Every run a partner hauls is split the same way — the haulier's percentage
 * and the rest. What changes is **who is already holding the money**, and that
 * is `trips.booking_source`:
 *
 *   `cargo_rush` — the haulier invoiced the customer and will collect. The
 *   partner's share is money the haulier has and owes onwards, so the wallet is
 *   credited it. On ₱10,000 at 12%: **+₱8,800**.
 *
 *   `direct` — a customer picked the partner off the hauler list, and the
 *   partner bills and collects from them. No invoice is raised at all; what the
 *   haulier is owed is its cut of a run it never touched, so the wallet is
 *   charged it. On ₱10,000 at 12%: **−₱1,200**.
 *
 * One row per run either way, never two. Writing a `+8,800` beside a `−1,200`
 * would net to ₱7,600 and be wrong, and it is the sort of wrong that survives
 * review because both figures are individually correct.
 *
 * ## Rounding
 *
 * The commission is rounded to the centavo and the partner gets the remainder.
 * That is a decision rather than an accident of `intdiv`: fractions of a
 * centavo have to land somewhere, and landing them on the side of the party who
 * did not write the software is the only version of this nobody has to argue
 * about. At 12% it is at most one centavo per run.
 *
 * ## Why a service rather than the accounting module
 *
 * These rows are not journal entries and this is not a second set of books.
 * They are the running account between a haulier and one contractor, in the
 * form that contractor can read on a phone. What the haulier's own ledger makes
 * of them — revenue on the commission, a payable for the balance — is the
 * accountant's posting to make from the statement, and doing it automatically
 * would put entries in the general journal that nobody at the desk chose.
 */
class WalletService
{
    /**
     * How a payout goes out unless somebody says otherwise.
     *
     * A transfer, because almost every payment to a contractor is one — and
     * because it is the case that takes a day, which is the whole reason a
     * payment has a status at all. Free text rather than an enum, exactly as
     * `payments.method` is: the list is a business's own and grows.
     */
    public const DEFAULT_METHOD = 'bank_transfer';

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Post the money for a delivered run.
     *
     * Called once per trip, from the same transaction that closes the delivery,
     * and idempotent twice over: the caller guards on `trips.billed_at`, and
     * the unique index on (`trip_id`, `kind`) is what makes a second call
     * impossible rather than merely unlikely. A partner's balance is the one
     * figure in this system somebody checks daily, and one that could be
     * inflated by pressing a button twice would not be a wallet.
     *
     * Returns the row it wrote, or null for a run with no partner or no price
     * — a free haul splits to nothing, and a row for ₱0 would be noise on a
     * statement rather than a record of anything.
     */
    public function settleTrip(Trip $trip, ?CarbonInterface $at = null): ?WalletEntry
    {
        $trucker = $trip->trucker;

        if ($trucker === null) {
            return null;
        }

        $gross = (int) $trip->price_cents;

        if ($gross <= 0) {
            return null;
        }

        $rateBp = $trucker->commissionRateBp();
        $commission = $this->commissionOn($gross, $rateBp);
        $source = $trip->booking_source ?? BookingSource::CargoRush;

        /**
         * The haulier collected, so it owes the partner the rest; or the
         * partner collected, so they owe the haulier the cut. One row, and
         * `signFor()` on the kind decides which way it points — passing a
         * magnitude here rather than a signed figure is what stops a caller
         * ever writing a negative earning.
         */
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

        // Frozen onto the trip as well, because the trip is what the office
        // opens when a partner queries a figure — and reading it back off the
        // wallet would mean finding the row first.
        $trip->forceFill([
            'commission_bp' => $rateBp,
            'commission_cents' => $commission,
        ])->save();

        $this->tellThePartner($trucker, $entry);

        return $entry;
    }

    /**
     * Post what a hired truck's owner is owed for a delivered run.
     *
     * The other way a wallet is credited, and the arithmetic is the mirror of
     * `settleTrip()`: the fleet collected from the customer, keeps its cut, and
     * owes the rest onwards. On ₱10,000 at 15%: the owner is credited ₱8,500.
     *
     * The difference from a partner's own run is **whose truck it is**. There
     * the partner found the work and the fleet takes a cut of somebody else's
     * business; here the fleet found the work and rents the wheels. Both end as
     * an `earning` on the same account because, to the person being paid, they
     * are the same thing — money the fleet is holding for them — and one
     * account that nets both is the whole point of having a wallet rather than
     * two reports.
     *
     * Returns null when the run is on the fleet's own truck, or on one already
     * paid for by the month, or when the terms name nobody to pay. Most trips
     * take that path and it costs one property read.
     */
    public function settleOwnerShare(Trip $trip, ?CarbonInterface $at = null): ?WalletEntry
    {
        $vehicle = $trip->vehicle;

        if ($vehicle === null || ! $vehicle->sharesRevenue()) {
            return null;
        }

        $gross = (int) $trip->price_cents;

        if ($gross <= 0) {
            return null;
        }

        $rateBp = $vehicle->shareRateBp();
        $owner = $vehicle->ownerPartner;

        if ($owner === null) {
            return null;
        }

        // The same truncation as everywhere else in this class, and the same
        // reason: the remainder lands with the party who did not write the
        // software.
        $share = $gross - $this->commissionOn($gross, $rateBp);

        $entry = $this->post(
            trucker: $owner,
            kind: WalletEntryKind::Earning,
            magnitude: $share,
            occurredOn: $at ?? now(),
            trip: $trip,
            // The fleet quoted, invoiced and collected this one — it is the
            // fleet's work on a truck it hired, not the owner's own business.
            source: BookingSource::CargoRush,
            grossCents: $gross,
            rateBp: $rateBp,
        );

        /**
         * Frozen onto the trip, exactly as a partner's run is.
         *
         * This was missing, and its absence was a real hole rather than an
         * untidiness: a hired-truck run showed `commission_bp` and
         * `commission_cents` as null, so the office screen could not say what
         * had been taken without finding the wallet row, and the audit story
         * the whole feature rests on — every settled run carries the rate it
         * closed at — held for partner work and quietly not for hired trucks.
         */
        $trip->forceFill([
            'commission_bp' => $rateBp,
            'commission_cents' => $gross - $share,
        ])->save();

        $this->tellThePartner($owner, $entry);

        return $entry;
    }

    /**
     * Hand money over for named runs, clearing each of them.
     *
     * **A payout is a set of runs, not an amount.** The amount is derived from
     * the rows it covers and cannot be typed, which is the whole change: a
     * payout used to be ₱26,400 against a balance of ₱26,400 with no link to
     * the three runs that made it up, and the first time a partner asked "have
     * you paid me for CR-24823?" there was no way to answer. Now the question
     * is a column.
     *
     * Passing no ids means every outstanding run, which is the ordinary case
     * and the one button the office presses. Naming a subset is the part
     * payment: two runs now, the third on Friday, and both states are recorded
     * rather than inferred from a total.
     *
     * There is no "pay an arbitrary figure" any more, and its absence is
     * deliberate. Money handed over that answers to no run is either a mistake
     * or an advance, and an advance is an `adjustment` with a reason written on
     * it — which keeps the balance and the paid state of the work from ever
     * disagreeing.
     *
     * @param  string[]  $entryIds  Outstanding earnings to clear. Empty means all of them.
     */
    public function payOut(
        Trucker $trucker,
        array $entryIds = [],
        ?string $reference = null,
        ?string $note = null,
        ?int $recordedBy = null,
        ?CarbonInterface $occurredOn = null,
        string $method = self::DEFAULT_METHOD,
        /**
         * Has the money already landed?
         *
         * False by default, because the default method is a transfer and a
         * transfer takes a day. Cash across a desk is the case that sets it
         * true — it has arrived by the time anybody types it.
         */
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

    /**
     * Confirm a payment has landed.
     *
     * The step that turns "on the way" into "paid", and the moment the
     * balance finally falls. Separate from recording the payment because
     * those are two different facts on two different days, and collapsing
     * them is what made the wallet claim a transfer had arrived the instant
     * somebody typed it.
     *
     * Refused on anything that is not a settlement in flight: an earning was
     * never in flight, and a payment already confirmed does not need
     * confirming twice.
     */
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
            // Who confirmed it, where nobody recorded who started it — the
            // two are usually the same person and the later one is the one
            // worth keeping.
            'recorded_by' => $settlement->recorded_by ?? $recordedBy,
        ]);

        $this->tellThePartnerItLanded($settlement->refresh());

        return $settlement;
    }

    /**
     * Money the partner has paid in, clearing the commission on named runs.
     *
     * The exact mirror of a payout, against the debt running the other way: a
     * partner who collected from the customer themselves owes the haulier its
     * cut, and remitting clears the runs it covers rather than reducing an
     * anonymous total.
     *
     * @param  string[]  $entryIds  Outstanding commissions to clear. Empty means all of them.
     */
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

    /**
     * The one write both settlements go through.
     *
     * Written once because the two are the same act in opposite directions, and
     * because the rules worth getting right are identical: the rows must be
     * this partner's, must be the right kind, and must not already be settled.
     * Two copies of that would eventually be one copy and one bug.
     *
     * The whole thing is a transaction. A settlement row that existed while the
     * runs it covers still read as unpaid would be money handed over twice by
     * the next person who looked.
     *
     * @param  string[]  $entryIds
     */
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
            /**
             * Locked for the length of the settle.
             *
             * Two people at the desk can press Pay on the same partner in the
             * same second — one from the roster, one from a statement they left
             * open. Without the lock both read the same outstanding rows and
             * both write a payout for them, and the partner is paid twice.
             */
            $outstanding = WalletEntry::query()
                ->where('trucker_id', $trucker->getKey())
                ->where('kind', $settling->value)
                ->whereNull('settled_by')
                ->when($entryIds !== [], static fn ($query) => $query->whereIn('id', $entryIds))
                ->lockForUpdate()
                ->get();

            abort_if($outstanding->isEmpty(), 422, $entryIds === []
                ? $nothingOwed
                : 'Those runs have already been settled, or are not this trucker\'s.');

            /**
             * Every id asked for has to have been found.
             *
             * Silently settling four of the five runs somebody ticked, and
             * reporting the smaller total as success, is the kind of partial
             * write nobody notices until the money is short.
             */
            abort_if(
                $entryIds !== [] && $outstanding->count() !== count(array_unique($entryIds)),
                422,
                'Some of those runs have already been settled. Reload and try again.',
            );

            $total = (int) $outstanding->sum(static fn (WalletEntry $entry): int => abs($entry->amount_cents));

            $settlement = $this->post(
                trucker: $trucker,
                kind: $with,
                magnitude: $total,
                occurredOn: $occurredOn ?? now(),
                reference: $reference,
                note: $note,
                recordedBy: $recordedBy,
                // In flight unless the office says it has already landed.
                // The balance does not move until it has — see
                // `WalletEntry::balanceFor()`.
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

    /**
     * A correction, in whichever direction, with a reason.
     *
     * The only entry whose sign the caller chooses, and the only one that
     * insists on a note. Every money system needs a way to fix what it got
     * wrong; the thing to avoid is a way to fix it without saying what was
     * fixed.
     */
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

    /**
     * What the account stands at, in centavos.
     *
     * Positive: the haulier owes the partner. Negative: the partner owes the
     * haulier. Summed rather than cached on the `truckers` row, and at this
     * volume that is not a performance question — a cached balance is a second
     * source of truth that can disagree with the rows, and when it does, the
     * partner is the one who notices.
     */
    /**
     * What the fleet actually handed to partners over a window, in centavos.
     *
     * Money that **left the bank**: payouts that have landed, dated by the day
     * they were made. An in-flight transfer is not counted — the office has
     * pressed a button and the bank has not moved yet, and the whole reason
     * `markLanded()` exists is that those are two different facts.
     *
     * The period reports read this as a cost of the window. Without it, paying
     * a partner made the business look *better*: the wallet balance fell, so
     * what the fleet owed fell with it, and nothing anywhere recorded that the
     * money had gone. See `FinanceService::periodTotals()`.
     *
     * ## Why a revenue-share truck's owner is left out
     *
     * The one exclusion here, and it would be a doubled figure without it. When
     * a run goes out on a truck the fleet hired on a share, `settleOwnerShare()`
     * credits the owner's wallet **and** the delivery writes the same amount to
     * the daily sheet as `owner_share_cents` — so the cost is already counted,
     * on the day the run was delivered. Paying that owner afterwards settles a
     * debt the books have already recognised, and counting the payout as well
     * would charge the fleet twice for one haul.
     *
     * A partner hauling in their own truck has no sheet and no such row, which
     * is why their payout is the only record that the money moved at all.
     */
    public function paidOutBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        // A payout is stored negative, because it is money leaving. The reports
        // want a magnitude to add to a column of costs.
        return abs((int) $this->payoutsLandedBetween($from, $to)->sum('amount_cents'));
    }

    /**
     * The same payouts, row by row, each with the day it was handed over.
     *
     * For a report that buckets by date rather than taking one total — Sales
     * does. Built from the same query as `paidOutBetween()`, exclusion and all,
     * so the total and the series cannot disagree about what counts.
     *
     * @return Collection<int, WalletEntry>
     */
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

    /**
     * Partners whose share of a run is already an expense the day it runs.
     *
     * The owners of revenue-share trucks. Read as a list of ids rather than
     * joined, because it is a handful of rows and the alternative is a
     * correlated subquery over `vehicles` on every period report.
     *
     * @return array<int, string>
     */
    private function ownersCostedOnDelivery(): array
    {
        return Vehicle::query()
            ->whereNotNull('owner_trucker_id')
            ->get()
            // The model's own predicate rather than a re-stated `where`: it
            // needs the arrangement, the owner and a share rate above zero, and
            // two copies of that rule is one place for them to drift.
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

    /**
     * The statement: what a partner's wallet screen and the office's tab both
     * read.
     *
     * @return Collection<int, WalletEntry>
     */
    public function statement(Trucker $trucker, ?string $from = null, ?string $to = null, int $limit = 100): Collection
    {
        return WalletEntry::query()
            ->with(['trip:id,reference,origin,destination', 'settlement:id,reference,occurred_on'])
            ->where('trucker_id', $trucker->getKey())
            ->when($from !== null, static fn ($query) => $query->whereDate('occurred_on', '>=', $from))
            ->when($to !== null, static fn ($query) => $query->whereDate('occurred_on', '<=', $to))
            // By date for the reader, then by id for the day: a ULID sorts by
            // the moment it was minted, so three entries on one day come back
            // in the order they happened rather than in whatever order the
            // database felt like.
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The figures above the statement.
     *
     * Earned and charged are reported separately rather than as one net number,
     * because they answer different questions — "what has this work been worth"
     * and "what has the platform taken" — and a partner comparing the two is
     * the whole reason the percentage is worth being transparent about.
     *
     * @return array<string, mixed>
     */
    public function summary(Trucker $trucker): array
    {
        $rows = WalletEntry::query()
            ->where('trucker_id', $trucker->getKey())
            /**
             * `entries`, not `rows`.
             *
             * `ROWS` is a reserved word in MySQL 8.0 — it is part of the window
             * function frame clause — so `count(*) as rows` is a syntax error
             * there and parses perfectly well under SQLite, which is what the
             * suite runs on. That combination is worth a comment rather than a
             * rename: the tests cannot catch it, so the only defence is not
             * writing it.
             */
            ->selectRaw('kind, sum(amount_cents) as total, count(*) as entries')
            ->groupBy('kind')
            ->get()
            ->keyBy(static fn (WalletEntry $entry): string => $entry->kind->value);

        /**
         * The same roll-up again, over the rows nobody has settled.
         *
         * One grouped query rather than four separate reads — this runs on
         * every wallet open, from the office tab and from every partner's
         * handset, and asking the same table four times for four numbers off
         * the same rows is three questions too many.
         */
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
            // Which way the account points, said in a word so no client has to
            // decide what a negative balance means. The two are not symmetric
            // on screen — one is money coming, the other is a bill — and a
            // client that got the sign backwards would tell somebody they were
            // owed what they in fact owe.
            'standing' => match (true) {
                $balance > 0 => 'owed_to_trucker',
                $balance < 0 => 'owed_to_company',
                default => 'settled',
            },
            'earned_cents' => $total(WalletEntryKind::Earning),
            // Reported as a positive magnitude: the row is negative because of
            // where it points, but "commission charged: ₱1,200" is what a
            // person reads, not "−₱1,200".
            'commission_cents' => abs($total(WalletEntryKind::Commission)),
            'paid_out_cents' => abs($total(WalletEntryKind::Payout)),
            'remitted_cents' => $total(WalletEntryKind::Remittance),
            'adjustments_cents' => $total(WalletEntryKind::Adjustment),
            'trips_settled' => (int) ($rows[WalletEntryKind::Earning->value]->entries ?? 0)
                + (int) ($rows[WalletEntryKind::Commission->value]->entries ?? 0),

            /**
             * What is still unpaid, run by run — the figures the settle form
             * is driven by.
             *
             * Not the same as the balance, and the difference is worth naming:
             * the balance includes adjustments, which are corrections to the
             * account rather than runs anybody can be paid for. A partner with
             * ₱26,400 of unpaid runs and a −₱5,000 adjustment has a balance of
             * ₱21,400 and ₱26,400 of payable work, and both figures are true.
             */
            'unpaid_earnings_cents' => (int) ($unsettled[WalletEntryKind::Earning->value]->total ?? 0),
            'unpaid_earnings_count' => (int) ($unsettled[WalletEntryKind::Earning->value]->entries ?? 0),
            // A magnitude: "commission due: ₱1,200" is what a person reads.
            'unremitted_commission_cents' => abs(
                (int) ($unsettled[WalletEntryKind::Commission->value]->total ?? 0),
            ),
            'unremitted_commission_count' => (int) ($unsettled[WalletEntryKind::Commission->value]->entries ?? 0),

            /**
             * Money the office has sent that has not landed yet.
             *
             * Reported beside the balance rather than folded into it, because
             * they are different facts and a partner needs both: "you are owed
             * ₱26,400, and ₱26,400 of it is on its way" is the honest sentence.
             * Folding it in would say they had been paid before the transfer
             * cleared, which is the thing this whole status exists to stop.
             */
            'in_flight_cents' => WalletEntry::inFlightFor($trucker->getKey()),
        ];
    }

    /**
     * A partner's unpaid work of one kind, newest first.
     *
     * What the settle form lists and what "pay all" covers. Public because the
     * office screen needs the rows themselves, not just their total — the point
     * of the whole change is that somebody picks which runs they are paying.
     *
     * @return Collection<int, WalletEntry>
     */
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

    /**
     * The haulier's cut of a figure, in centavos.
     *
     * Public because the job board quotes it before anybody has hauled
     * anything — a partner deciding whether to take a run is entitled to see
     * what they would clear on it, and a board that showed the gross would be
     * quoting a number nobody receives.
     */
    public function commissionOn(int $grossCents, int $rateBp): int
    {
        // Integer arithmetic start to finish. `intdiv` truncates, which rounds
        // the haulier's cut down and leaves the remainder with the partner —
        // see the class docblock for why that is the right direction.
        return intdiv($grossCents * $rateBp, 10_000);
    }

    /**
     * Write the row.
     *
     * The single insert every public method above funnels through, so the sign
     * rule, the company stamp and the shape of a row exist in one place. The
     * magnitude is made positive first and then signed by the kind: an
     * `earning` is credited whether the caller passed 8,800 or −8,800, which
     * removes a whole class of mistake from the callers.
     */
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
        /**
         * Has this landed?
         *
         * `paid` for everything except a settlement the office has started and
         * not confirmed. An earning is a fact about work rather than a payment
         * in flight, so it is never `pending` — see the migration.
         */
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

    /**
     * Tell the partner what just landed.
     *
     * The moment a run closes is the moment somebody wants to know what it was
     * worth, and a wallet they have to open to find out is a wallet they will
     * not trust. Skipped for a partner with no login — the desk may have put
     * them on the books before they had the app.
     */
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

    /**
     * Tell the partner the money has actually landed.
     *
     * The notification that matters most of the three this class sends. "We
     * have sent it" is useful; "it has arrived" is what somebody checks their
     * bank for, and a wallet that went quiet between the two would have them
     * ringing the office to ask.
     */
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

    /** Centavos as a peso figure, for the one-line messages above. */
    private function peso(int $cents): string
    {
        return '₱'.number_format($cents / 100, 2);
    }
}
