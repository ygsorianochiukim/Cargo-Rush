<?php

declare(strict_types=1);

namespace App\Domain\Trucker\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\BookingSource;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One line of a partner's wallet.
 *
 * ## What the balance means
 *
 * The balance is the sum of `amount_cents`, and its **sign is the direction of
 * the debt**:
 *
 *   **Positive** — the haulier owes the partner. It collected a customer's
 *   money for a run the partner hauled and has not handed their share over yet.
 *   A `payout` clears it.
 *
 *   **Negative** — the partner owes the haulier. They collected a customer's
 *   money themselves on a run a customer gave them directly, and the haulier's
 *   cut of it is still with them. A `remittance` clears it.
 *
 * One partner is ordinarily both at once — some weeks of company work, some of
 * their own — and the two net against each other, which is the point of having
 * one account rather than a payable and a receivable that never meet. A partner
 * who did ₱88,000 of company work and owes ₱1,200 on a direct run is paid
 * ₱86,800 and nobody writes a cheque in the other direction.
 *
 * ## What is frozen here, and why
 *
 * `source`, `gross_cents` and `rate_bp` are copied from the trip when the row
 * is written and never touched again. They are not denormalisation for speed:
 * they are the row explaining itself. A trip can be re-priced, corrected, or
 * deleted outright, and a partner asking in November why a figure in March is
 * what it is deserves an answer that does not depend on a record somebody has
 * since edited.
 */
class WalletEntry extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $table = 'trucker_wallet_entries';

    protected $fillable = [
        'trucker_id', 'trip_id', 'kind', 'status', 'method', 'source', 'amount_cents',
        'gross_cents', 'rate_bp', 'reference', 'note', 'occurred_on', 'recorded_by',
        // Stamped by `WalletService` when a payout or a remittance clears this
        // row. Fillable so the settle writes it in one update; nothing else
        // sets it, and no payload reaches it.
        'settled_by', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'gross_cents' => 'integer',
            'rate_bp' => 'integer',
            'occurred_on' => 'date',
            'settled_at' => 'datetime',
            'kind' => WalletEntryKind::class,
            'status' => StatusValue::class,
            'source' => BookingSource::class,
        ];
    }

    /**
     * Money the office has sent that has not landed yet.
     *
     * Only ever true of a payout or a remittance. An earning is a fact about
     * work rather than a payment, so it is `paid` from the moment it exists.
     */
    public function isInFlight(): bool
    {
        return $this->status === StatusValue::Pending;
    }

    /** Rows that have actually landed — the only ones the balance counts. */
    public function scopeLanded(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Paid->value);
    }

    /** Payments the office has started and not yet confirmed. */
    public function scopeInFlight(Builder $query): Builder
    {
        return $query->where('status', StatusValue::Pending->value);
    }

    public function trucker(): BelongsTo
    {
        return $this->belongsTo(Trucker::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * What this row is about, in the words a partner would use.
     *
     * Built here rather than on the client because it is the same sentence on
     * three screens — the handset's wallet, the office's statement and the
     * export — and three of them would drift.
     */
    public function describe(): string
    {
        $reference = $this->trip?->reference;

        return match ($this->kind) {
            WalletEntryKind::Earning => $reference === null
                ? 'Trip earning'
                : "Earned on {$reference}",
            WalletEntryKind::Commission => $reference === null
                ? 'Commission'
                : "Commission on {$reference}",
            WalletEntryKind::Payout => 'Paid out'.($this->reference === null ? '' : " · {$this->reference}"),
            WalletEntryKind::Remittance => 'Remitted'.($this->reference === null ? '' : " · {$this->reference}"),
            WalletEntryKind::Adjustment => $this->note ?? 'Adjustment',
        };
    }

    /**
     * The running balance — **what has landed, and nothing else**.
     *
     * A payout the office has started but not confirmed is money committed
     * rather than money arrived, so the partner is still owed it. A transfer
     * that bounces has paid nobody, and a balance that had already fallen to
     * nought would have to be corrected by hand — by which point the partner
     * has been told twice that they were paid.
     *
     * This does not risk paying twice. A run leaves the payable list the
     * moment it is *settled*, which `settled_by` records quite separately from
     * whether the money has cleared.
     */
    public static function balanceFor(string $truckerId): int
    {
        return (int) static::query()
            ->where('trucker_id', $truckerId)
            ->landed()
            ->sum('amount_cents');
    }

    /** What the office has sent this partner that has not landed yet. */
    public static function inFlightFor(string $truckerId): int
    {
        return abs((int) static::query()
            ->where('trucker_id', $truckerId)
            ->inFlight()
            ->sum('amount_cents'));
    }

    /** Rows a partner earned or was charged on a haul, as against settlements. */
    public function scopeFromWork(Builder $query): Builder
    {
        return $query->whereIn('kind', [
            WalletEntryKind::Earning->value,
            WalletEntryKind::Commission->value,
        ]);
    }

    /**
     * The payout or remittance that cleared this row, if one has.
     *
     * Its `reference` is what the statement prints beside "Paid" — a cheque
     * number is the thing a partner actually checks against their own records.
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'settled_by');
    }

    /**
     * Has this run been paid for?
     *
     * Only ever asked of work rows. A payout is not itself paid or unpaid — it
     * *is* the payment — and an adjustment is a correction to the balance
     * rather than a run somebody is owed for, so both answer false and neither
     * is ever offered for settlement.
     */
    public function isSettled(): bool
    {
        return $this->settled_by !== null;
    }

    /** Work a partner is still owed for, or still owes on. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->fromWork()->whereNull('settled_by');
    }

    /**
     * Where a run's payment has got to — three states, not two.
     *
     * `unpaid` nobody has started; `processing` the money is on its way;
     * `paid` it landed. Worked out here because it spans two rows — this
     * one's `settled_by` and the settlement's own status — and leaving each
     * client to combine them would be three chances to tell a partner they
     * have been paid while the transfer is still in the air.
     *
     * A payment row and an adjustment answer `null`: neither is a run
     * awaiting payment, and giving them a state would put a "PAID" pill on
     * the payment itself.
     */
    public function paymentState(): ?string
    {
        if (! $this->kind->isFromWork()) {
            return null;
        }

        if (! $this->isSettled()) {
            return 'unpaid';
        }

        return $this->settlement?->isInFlight() ? 'processing' : 'paid';
    }
}
