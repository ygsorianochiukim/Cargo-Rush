<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\PaymentSource;
use App\Domain\Accounting\Repositories\PaymentSourceRepository;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;

class PaymentSourceService extends CrudService
{
    public function __construct(private readonly PaymentSourceRepository $sources) {}

    protected function repository(): Repository
    {
        return $this->sources;
    }

    public function retire(PaymentSource $source): bool
    {
        if ($source->isInUse()) {
            $source->update(['status' => StatusValue::Inactive->value]);

            return false;
        }

        $source->delete();

        return true;
    }
}
