<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Services;

use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Repositories\SupplierRepository;
use Illuminate\Support\Collection;

/**
 * Suppliers — the plain five verbs, plus what one has cost.
 *
 * Nothing clever in the CRUD, which is why it is `CrudService`. What is here
 * is the history: the three places a supplier's money shows up, gathered in
 * the order somebody asks for them.
 */
class SupplierService extends CrudService
{
    public function __construct(private readonly SupplierRepository $suppliers) {}

    protected function repository(): Repository
    {
        return $this->suppliers;
    }

    /**
     * Everything bought from one supplier.
     *
     * Three lists rather than one merged feed, and deliberately: an expense, a
     * service and a bill are settled on three different screens under three
     * different permissions, and flattening them into "transactions" would
     * hide which of the three a row is and therefore where to go to act on it.
     *
     * @return array{expenses: Collection, maintenance: Collection, bills: Collection}
     */
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
