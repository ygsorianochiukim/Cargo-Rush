<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Services;

use App\Domain\Dispatch\Models\DispatchRecord;
use App\Domain\Dispatch\Repositories\DispatchRecordRepository;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use Illuminate\Support\Facades\DB;

class DispatchService extends CrudService
{
    public function __construct(private readonly DispatchRecordRepository $records) {}

    protected function repository(): Repository
    {
        return $this->records;
    }

    public function markArrived(DispatchRecord $record): DispatchRecord
    {
        return DB::transaction(function () use ($record): DispatchRecord {
            $now = now();

            $record->update(['arrived_at' => $now, 'status' => StatusValue::Delivered->value]);
            $record->trip?->update(['status' => StatusValue::Delivered->value]);

            return $record->refresh();
        });
    }
}
