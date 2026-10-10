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

    public function query(): Builder
    {
        return DieselPrice::query()->orderByDesc('effective_on');
    }

    public function current(?Carbon $on = null): ?DieselPrice
    {
        return $this->query()
            ->whereDate('effective_on', '<=', ($on ?? Carbon::now())->toDateString())
            ->first();
    }

    /** @return Collection<int, DieselPrice> */
    public function history(int $days = 60): Collection
    {
        return $this->query()
            ->whereDate('effective_on', '>=', Carbon::now()->subDays($days)->toDateString())
            ->reorder('effective_on')
            ->get();
    }

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
