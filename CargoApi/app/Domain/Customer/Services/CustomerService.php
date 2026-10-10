<?php

declare(strict_types=1);

namespace App\Domain\Customer\Services;

use App\Domain\Customer\DTO\CustomerData;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Repositories\CustomerRepository;
use App\Domain\Identity\Services\CustomerAccountService;
use App\Domain\Shared\DTO\Data;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerService extends CrudService
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CustomerAccountService $accounts,
    ) {}

    protected function repository(): Repository
    {
        return $this->customers;
    }

    public function create(Data $data): Model
    {
        return DB::transaction(function () use ($data): Customer {
            /** @var Customer $customer */
            $customer = parent::create($data);

            return $this->withLogin($customer, $this->emailIn($data) ?? $customer->contact);
        });
    }

    public function update(Model $model, Data $data): Model
    {
        return DB::transaction(function () use ($model, $data): Customer {
            /** @var Customer $customer */
            $customer = parent::update($model, $data);

            return $this->withLogin($customer, $this->emailIn($data));
        });
    }

    private function withLogin(Customer $customer, ?string $address): Customer
    {
        $customer->newLogin = $this->accounts->ensureFor($customer, $address);

        return $customer->load('logins');
    }

    private function emailIn(Data $data): ?string
    {
        return $data instanceof CustomerData ? $data->email : null;
    }

    /** @return array{trips: Collection, invoices: Collection, ledger_entries: Collection} */
    public function history(Customer $customer, int $limit = 25): array
    {
        return [
            'trips' => $customer->trips()
                ->with(['driver:id,name', 'vehicle:id,plate'])
                ->orderByDesc('scheduled_at')
                ->limit($limit)
                ->get(),
            'invoices' => $customer->invoices()
                ->orderByDesc('issued_at')
                ->limit($limit)
                ->get(),
            'ledger_entries' => $customer->ledgerEntries()
                ->with(['truck:id,label,plate', 'trip:id,reference'])
                ->orderByDesc('date')
                ->limit($limit)
                ->get(),
        ];
    }
}
