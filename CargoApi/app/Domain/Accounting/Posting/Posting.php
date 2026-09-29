<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Shared\Enums\BalanceSide;
use App\Domain\Shared\Enums\JournalCategory;
use Carbon\CarbonInterface;

/**
 * One journal entry a record wants in the books, before it is written.
 *
 * What a `PostingRule` returns and `AutoPostingService` compares against what
 * is already posted. Lines name accounts by **code**, not id: the rule knows
 * the chart only as configuration, and the service resolves the codes once for
 * the whole set — so a missing account is found before anything is written.
 *
 * ## Signed amounts flip sides rather than go negative
 *
 * A negative debit is written as a credit. The sheet's income column can be
 * corrected below zero, a rounding difference can point either way, and a
 * journal line carries its amount on one side as a positive figure (see
 * `JournalLine`). Zero is dropped: a line of nothing is noise in the ledger.
 */
final class Posting
{
    /** @var array<int, array{code: string, side: BalanceSide, cents: int, memo: ?string, truck_id: ?string, trip_id: ?string, customer_id: ?string}> */
    private array $lines = [];

    /** @var array{truck_id?: ?string, trip_id?: ?string, customer_id?: ?string} */
    private array $dimensions = [];

    public function __construct(
        /** Which posting of the record this is — `issue`, `payment`, `sheet`. Never empty. */
        public readonly string $rule,
        public readonly CarbonInterface $date,
        public readonly JournalCategory $category,
        public readonly string $memo,
    ) {}

    /**
     * What every line after this is about — the truck, the trip, the customer.
     *
     * @param  array{truck_id?: ?string, trip_id?: ?string, customer_id?: ?string}  $dimensions
     */
    public function about(array $dimensions): self
    {
        $this->dimensions = $dimensions;

        return $this;
    }

    public function debit(string $code, int $cents, ?string $memo = null): self
    {
        return $this->line($code, BalanceSide::Debit, $cents, $memo);
    }

    public function credit(string $code, int $cents, ?string $memo = null): self
    {
        return $this->line($code, BalanceSide::Credit, $cents, $memo);
    }

    /** @return array<int, array{code: string, side: BalanceSide, cents: int, memo: ?string, truck_id: ?string, trip_id: ?string, customer_id: ?string}> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return string[] */
    public function codes(): array
    {
        return array_values(array_unique(array_column($this->lines, 'code')));
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function isBalanced(): bool
    {
        $net = 0;

        foreach ($this->lines as $line) {
            $net += $line['side'] === BalanceSide::Debit ? $line['cents'] : -$line['cents'];
        }

        return $net === 0;
    }

    private function line(string $code, BalanceSide $side, int $cents, ?string $memo): self
    {
        if ($cents === 0) {
            return $this;
        }

        if ($cents < 0) {
            $side = $side === BalanceSide::Debit ? BalanceSide::Credit : BalanceSide::Debit;
            $cents = -$cents;
        }

        $this->lines[] = [
            'code' => $code,
            'side' => $side,
            'cents' => $cents,
            'memo' => $memo,
            'truck_id' => $this->dimensions['truck_id'] ?? null,
            'trip_id' => $this->dimensions['trip_id'] ?? null,
            'customer_id' => $this->dimensions['customer_id'] ?? null,
        ];

        return $this;
    }
}
