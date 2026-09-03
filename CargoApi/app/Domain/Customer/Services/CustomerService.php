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

    /**
     * A new firm, and the login that comes with it.
     *
     * The account is the point: a customer added at the desk can sign in and
     * book their own work the same day, instead of existing as a row nobody
     * outside the office can reach. In one transaction, because a firm on the
     * books whose account failed to save is exactly the half-finished state
     * this is meant to remove.
     *
     * The starting password travels back on the model, not in the record — see
     * `Customer::$newLogin`. It is the one moment the office can read it and
     * pass it on.
     */
    public function create(Data $data): Model
    {
        return DB::transaction(function () use ($data): Customer {
            /** @var Customer $customer */
            $customer = parent::create($data);

            // The contact stands in when no login address was typed, because
            // for most firms it already is one — `ops@southline.ph` is both the
            // address the office writes to and the one the firm signs in with,
            // and asking for it twice would only invite them to disagree.
            return $this->withLogin($customer, $this->emailIn($data) ?? $customer->contact);
        });
    }

    /**
     * An edit, and a login for a firm that has none.
     *
     * How the customers already on the books get theirs: every one of them
     * predates the account being created with the record. Idempotent, so the
     * password of a firm that has an account — and may well have changed it —
     * is never reset by an edit.
     *
     * Note what create does and this does not: fall back to the contact. A
     * login has to be asked for here, because minting an account for a firm
     * because somebody corrected its rating is a surprise, and the desk would
     * have no idea credentials now exist for an address it did not name.
     */
    public function update(Model $model, Data $data): Model
    {
        return DB::transaction(function () use ($model, $data): Customer {
            /** @var Customer $customer */
            $customer = parent::update($model, $data);

            return $this->withLogin($customer, $this->emailIn($data));
        });
    }

    /**
     * Ensure the account, and hand the credentials back on the instance.
     *
     * `load()` because the record is about to be serialised: the resource
     * prints the address the firm signs in with, and a relation read before
     * the account existed would still be empty.
     */
    private function withLogin(Customer $customer, ?string $address): Customer
    {
        $customer->newLogin = $this->accounts->ensureFor($customer, $address);

        return $customer->load('logins');
    }

    /** The login address the caller asked for, if this payload carries one. */
    private function emailIn(Data $data): ?string
    {
        return $data instanceof CustomerData ? $data->email : null;
    }

    /**
     * Transaction history — what one customer has behind them, which is what
     * the detail view means by "history" in DESIGN.md section 5.1.
     *
     * Three parts, because they answer different questions: the trips are what
     * was hauled, the invoices are what was billed for it, and the ledger rows
     * are what it earned and cost. History carried only the first two, so a
     * customer with a month of hauling behind them showed no money at all.
     *
     * @return array{trips: Collection, invoices: Collection, ledger_entries: Collection}
     */
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
            // What the work earned and cost, which the trip and the invoice
            // between them do not say.
            'ledger_entries' => $customer->ledgerEntries()
                ->with(['truck:id,label,plate', 'trip:id,reference'])
                ->orderByDesc('date')
                ->limit($limit)
                ->get(),
        ];
    }
}
