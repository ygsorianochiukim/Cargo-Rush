<?php

declare(strict_types=1);

namespace App\Domain\Finance\DTO;

use App\Domain\Shared\DTO\Data;

/**
 * One day of trip income and expenses.
 *
 * There are no `total_expenses_cents` or `net_income_cents` fields, and there
 * never will be: both are derived (DESIGN.md section 5.1). Accepting them
 * would let a client post a total that disagrees with its own parts.
 */
final class LedgerEntryData extends Data
{
    public function __construct(
        public readonly ?string $truck_id = null,
        public readonly ?string $customer_id = null,
        /** Who the day's driver salary belonged to. */
        public readonly ?string $driver_id = null,
        /**
         * The day's helpers and what each was paid, in order.
         *
         * Null when the caller did not mention them. Written to
         * `ledger_entry_helpers` by `FinanceService`, so not in `toArray()`.
         *
         * @var array<int, array{driver_id: ?string, salary_cents: int}>|null
         */
        public readonly ?array $helpers = null,
        public readonly ?string $date = null,
        public readonly ?int $trip_income_cents = null,
        public readonly ?int $fuel_cents = null,
        public readonly ?int $driver_salary_cents = null,
        /**
         * One figure for all the day's helpers — what a client that predates
         * the per-helper lines still sends. Never written straight to the
         * column: `FinanceService` turns it into a line, and the column is
         * always the sum of the lines.
         */
        public readonly ?int $helper_salary_cents = null,
        public readonly ?int $maintenance_cents = null,
        public readonly ?int $allowance_cents = null,
        public readonly ?string $route = null,
        public readonly ?string $remarks = null,
        public ?int $recorded_by = null,
    ) {}

    protected static function hydrate(array $attributes): static
    {
        $cents = static fn (string $key): ?int => isset($attributes[$key])
            ? (int) $attributes[$key]
            : null;

        return new self(
            truck_id: $attributes['truck_id'] ?? null,
            customer_id: $attributes['customer_id'] ?? null,
            driver_id: $attributes['driver_id'] ?? null,
            helpers: isset($attributes['helpers'])
                ? array_values(array_map(static fn (array $line): array => [
                    'driver_id' => $line['driver_id'] ?? null,
                    'salary_cents' => (int) ($line['salary_cents'] ?? 0),
                ], (array) $attributes['helpers']))
                : null,
            date: $attributes['date'] ?? null,
            trip_income_cents: $cents('trip_income_cents'),
            fuel_cents: $cents('fuel_cents'),
            driver_salary_cents: $cents('driver_salary_cents'),
            helper_salary_cents: $cents('helper_salary_cents'),
            maintenance_cents: $cents('maintenance_cents'),
            allowance_cents: $cents('allowance_cents'),
            route: $attributes['route'] ?? null,
            remarks: $attributes['remarks'] ?? null,
            recorded_by: $cents('recorded_by'),
        );
    }

    public function toArray(): array
    {
        return [
            'truck_id' => $this->truck_id,
            'customer_id' => $this->customer_id,
            'driver_id' => $this->driver_id,
            'date' => $this->date,
            'trip_income_cents' => $this->trip_income_cents,
            'fuel_cents' => $this->fuel_cents,
            'driver_salary_cents' => $this->driver_salary_cents,
            'maintenance_cents' => $this->maintenance_cents,
            'allowance_cents' => $this->allowance_cents,
            'route' => $this->route,
            'remarks' => $this->remarks,
            'recorded_by' => $this->recorded_by,
        ];
    }

    /**
     * Who recorded it is set by the service from the token, never by the
     * client — so `recorded_by` joins the provided keys here rather than
     * arriving in the payload.
     */
    public function recordedBy(?int $userId): self
    {
        $clone = clone $this;
        $clone->recorded_by = $userId;
        $clone->provided = array_values(array_unique([...$this->provided, 'recorded_by']));

        return $clone;
    }
}
