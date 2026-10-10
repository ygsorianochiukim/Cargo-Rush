<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Services;

use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Repositories\SupplierRepository;
use Illuminate\Support\Collection;

class SupplierService extends CrudService
{
    public function __construct(private readonly SupplierRepository $suppliers) {}

    protected function repository(): Repository
    {
        return $this->suppliers;
    }

    /** @return array{expenses: Collection, maintenance: Collection, bills: Collection} */
    public function history(Supplier $supplier): array
    {
        return [
            'expenses' => $supplier->expenses()
                ->with('category:id,key,name,icon')
                ->orderByDesc('date')
                ->limit(100)
                ->get(),

            'maintenance' => $supplier->maintenanceJobs()
                ->with('vehicle:id,plate')
                ->orderByDesc('completed_on')
                ->limit(100)
                ->get(),

            'bills' => $supplier->bills()
                ->orderByDesc('issued_at')
                ->limit(100)
                ->get(),
        ];
    }
}
