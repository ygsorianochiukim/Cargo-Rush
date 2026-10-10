<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Repositories;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;

class VehicleRepository extends Repository
{
    protected function model(): string
    {
        return Vehicle::class;
    }

    public function query(): Builder
    {
        return Vehicle::query()
            ->with(['driver:id,name', 'ownerPartner:id,name'])
            ->orderBy('plate');
    }

    protected function searchable(): array
    {
        return ['plate', 'model', 'registration_no'];
    }

    public function findByPlate(string $plate): ?Vehicle
    {
        return $this->query()->where('plate', $plate)->first();
    }

    /** @return array<string, int> */
    public function countsByStatus(): array
    {
        return Vehicle::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn ($n): int => (int) $n)
            ->all();
    }

    public function utilisation(): float
    {
        $total = Vehicle::query()->count();
        if ($total === 0) {
            return 0.0;
        }

        $working = Vehicle::query()->where('status', StatusValue::Active->value)->count();

        return round($working / $total * 100, 1);
    }
}
