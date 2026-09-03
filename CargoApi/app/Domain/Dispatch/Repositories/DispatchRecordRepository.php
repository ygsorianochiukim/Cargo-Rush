<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Repositories;

use App\Domain\Dispatch\Models\DispatchRecord;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;

class DispatchRecordRepository extends Repository
{
    protected function model(): string
    {
        return DispatchRecord::class;
    }

    public function query(): Builder
    {
        return DispatchRecord::query()
            ->with(['trip:id,reference', 'vehicle:id,plate'])
            ->orderByDesc('dispatched_at');
    }

    protected function searchable(): array
    {
        return ['location'];
    }

    public function forTrip(string $tripId): ?DispatchRecord
    {
        return $this->query()->where('trip_id', $tripId)->first();
    }
}
