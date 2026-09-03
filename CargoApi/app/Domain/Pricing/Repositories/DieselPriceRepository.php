<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Repositories;

use App\Domain\Pricing\Models\DieselPrice;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class DieselPriceRepository extends Repository
{
    protected function model(): string
    {
        return DieselPrice::class;
    }

    /** Newest first — every view of this table is "what is it now". */
    public function query(): Builder
    {
        return DieselPrice::query()->orderByDesc('effective_on');
    }

    /**
     * The price in force, which is the most recent reading not in the future.
     *
     * Not simply the newest row: the office can record tomorrow's announced
     * price today, and quoting from it before it takes effect would charge a
     * surcharge for a rise that has not happened yet.
     */
    public function current(?Carbon $on = null): ?DieselPrice
    {
        return $this->query()
            ->whereDate('effective_on', '<=', ($on ?? Carbon::now())->toDateString())
            ->first();
    }

    /**
     * Readings over a window, oldest first, for the trend line.
     *
     * @return Collection<int, DieselPrice>
     */
    public function history(int $days = 60): Collection
    {
        return $this->query()
            ->whereDate('effective_on', '>=', Carbon::now()->subDays($days)->toDateString())
            ->reorder('effective_on')
            ->get();
    }

    /**
     * Record a day's price, correcting it if the day already has one.
     *
     * An update rather than a second insert: the table is one row per day by
     * constraint, and the office keying today's price twice means they are
     * fixing a typo, not asking for a second reading.
     *
     * The lookup is `whereDate` and not a plain `updateOrCreate` on the column.
     * `effective_on` is a date-cast attribute, which Eloquent still writes
     * through the model's `Y-m-d H:i:s` format, so the stored value carries a
     * midnight time — matching it against a bare `Y-m-d` misses, and the miss
     * becomes an insert that hits the unique index as a 500. The ledger has the
     * same trap for the same reason (`FinanceService::openDailyRow`).
     */
    public function record(string $date, int $centsPerLitre, ?string $source, ?int $userId): DieselPrice
    {
        $attributes = [
            'price_per_litre_cents' => $centsPerLitre,
            'source' => $source,
            'recorded_by' => $userId,
        ];

        $existing = DieselPrice::whereDate('effective_on', $date)->first();

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing->refresh();
        }

        return DieselPrice::create([...$attributes, 'effective_on' => $date])->refresh();
    }
}
