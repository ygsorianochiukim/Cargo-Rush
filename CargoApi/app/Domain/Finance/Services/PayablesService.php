<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Finance\Models\Expense;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Trucker\Services\WalletService;
use App\Domain\Vehicle\Models\MaintenanceJob;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Services\TruckRentService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PayablesService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly PayrollCostService $payroll,
    ) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $groups = [
            $this->truckers(),
            $this->rentedTrucks(),
            $this->supplierBills(),
            $this->payrollOwed(),
            $this->otherSpend(),
        ];

        return [
            'total_cents' => array_sum(array_column($groups, 'total_cents')),
            'in_flight_cents' => array_sum(array_column($groups, 'in_flight_cents')),
            'groups' => $groups,
        ];
    }

    public function outstandingAsOf(CarbonInterface $asOf): int
    {
        return $this->truckersOwedAsOf($asOf)
            + $this->spendUnsettledAsOf($asOf)
            + $this->billsUnpaidAsOf($asOf)
            + $this->payroll->owedCentsAsOf($asOf);
    }

    public function alreadyCostedAsOf(CarbonInterface $asOf): int
    {
        return $this->wallet->heldForCostedOwnersAsOf($asOf)
            + $this->payroll->costedOwedAsOf($asOf)
            + $this->servicedBillsOwedAsOf($asOf);
    }

    private function servicedBillsOwedAsOf(CarbonInterface $asOf): int
    {
        $posted = MaintenanceJob::query()
            ->whereNotNull('invoice_id')
            ->where('posted_cents', '>', 0)
            ->whereDate('completed_on', '<=', $asOf->toDateString())
            ->selectRaw('invoice_id, sum(posted_cents) as posted')
            ->groupBy('invoice_id')
            ->pluck('posted', 'invoice_id');

        if ($posted->isEmpty()) {
            return 0;
        }

        return (int) $this->billsOwedAsOf($asOf)
            ->filter(static fn (array $bill): bool => $posted->has($bill['invoice']->getKey()))
            ->sum(static fn (array $bill): int => min($bill['owed_cents'], (int) $posted[$bill['invoice']->getKey()]));
    }

    private function truckersOwedAsOf(CarbonInterface $asOf): int
    {
        return (int) WalletEntry::query()
            ->landed()
            ->whereDate('occurred_on', '<=', $asOf->toDateString())
            ->selectRaw('trucker_id, sum(amount_cents) as balance')
            ->groupBy('trucker_id')
            ->pluck('balance')
            ->filter(static fn ($balance): bool => (int) $balance > 0)
            ->sum();
    }

    private function spendUnsettledAsOf(CarbonInterface $asOf): int
    {
        return (int) Expense::query()
            ->where('status', StatusValue::Pending->value)
            ->whereDate('date', '<=', $asOf->toDateString())
            ->sum('amount_cents');
    }

    private function billsUnpaidAsOf(CarbonInterface $asOf): int
    {
        return (int) $this->billsOwedAsOf($asOf)->sum('owed_cents');
    }

    /** @return Collection<int, array{invoice: Invoice, owed_cents: int}> */
    private function billsOwedAsOf(CarbonInterface $asOf): Collection
    {
        $date = $asOf->toDateString();

        return Invoice::query()
            ->with('customer:id,name')
            ->where('direction', InvoiceDirection::Payable->value)
            ->where('status', '!=', StatusValue::Cancelled->value)
            ->whereDate('issued_at', '<=', $date)
            ->withSum(
                ['allocations as paid_by_then' => static fn (Builder $query) => $query
                    ->whereHas('payment', static fn (Builder $payment) => $payment
                        ->whereDate('paid_on', '<=', $date))],
                'amount_cents',
            )
            ->orderBy('due_at')
            ->get()
            ->map(static function (Invoice $invoice) use ($date): array {
                $settledByFlag = $invoice->status === StatusValue::Paid
                    && ($invoice->paid_at === null || $invoice->paid_at->toDateString() <= $date);

                return [
                    'invoice' => $invoice,
                    'owed_cents' => $settledByFlag ? 0 : max(
                        0,
                        (int) $invoice->amount_cents - (int) ($invoice->paid_by_then ?? 0),
                    ),
                ];
            })
            ->filter(static fn (array $bill): bool => $bill['owed_cents'] > 0)
            ->values();
    }

    /** @return array<string, mixed> */
    private function truckers(): array
    {
        $partners = Trucker::query()->orderBy('name')->get();

        if ($partners->isEmpty()) {
            return $this->group('truckers', 'Truckers', 'fleet', []);
        }

        $byPartner = $this->arrangementsByPartner();

        $rentalOwners = $this->shareRentalsByOwner();

        $lines = $partners
            ->reject(static fn (Trucker $trucker): bool => isset($rentalOwners[$trucker->getKey()]))
            ->map(function (Trucker $trucker) use ($byPartner): ?array {
                $balance = WalletEntry::balanceFor($trucker->getKey());

                if ($balance <= 0) {
                    return null;
                }

                $inFlight = WalletEntry::inFlightFor($trucker->getKey());

                return [
                    'id' => $trucker->getKey(),
                    'name' => $trucker->name,
                    'detail' => $byPartner[$trucker->getKey()] ?? 'Partner trucker',
                    'amount_cents' => $balance,
                    'in_flight_cents' => $inFlight,
                    'due_on' => null,
                    'settle_at' => '/truckers',
                ];
            })
            ->filter()
            ->values()
            ->all();

        return $this->group('truckers', 'Truckers', 'fleet', $lines);
    }

    /** @return array<string, mixed> */
    private function rentedTrucks(): array
    {
        $monthly = Expense::query()
            ->with('vehicle:id,plate,owner_name')
            ->whereHas('category', static fn ($query) => $query->where('key', TruckRentService::CATEGORY_KEY))
            ->where('status', StatusValue::Pending->value)
            ->orderBy('date')
            ->get()
            ->map(static fn (Expense $expense): array => [
                'id' => $expense->getKey(),
                'name' => $expense->vehicle?->plate ?? 'Rented truck',
                'detail' => trim(($expense->payee ?? 'Owner not recorded').' · '.($expense->note ?? '')),
                'amount_cents' => (int) $expense->amount_cents,
                'in_flight_cents' => 0,
                'due_on' => $expense->date?->toDateString(),
                'settle_at' => '/expenses',
            ]);

        $shared = collect($this->shareRentalsByOwner())
            ->map(static function (array $trucks, string $ownerId): ?array {
                $balance = WalletEntry::balanceFor($ownerId);

                if ($balance <= 0) {
                    return null;
                }

                $runs = WalletEntry::query()
                    ->where('trucker_id', $ownerId)
                    ->where('kind', WalletEntryKind::Earning->value)
                    ->outstanding()
                    ->count();

                $rates = collect($trucks)
                    ->map(static fn (Vehicle $vehicle): string => rtrim(rtrim(number_format($vehicle->shareRateBp() / 100, 2), '0'), '.').'%')
                    ->unique()
                    ->implode(' / ');

                return [
                    'id' => $ownerId,
                    'name' => collect($trucks)->pluck('plate')->implode(', '),
                    'detail' => sprintf(
                        '%s · revenue share, fleet keeps %s · %d unpaid %s',
                        $trucks[0]->ownerPartner?->name ?? 'Owner not recorded',
                        $rates,
                        $runs,
                        $runs === 1 ? 'run' : 'runs',
                    ),
                    'amount_cents' => $balance,
                    'in_flight_cents' => WalletEntry::inFlightFor($ownerId),
                    'due_on' => null,
                    'settle_at' => '/truckers',
                ];
            })
            ->filter()
            ->values();

        return $this->group('rented_trucks', 'Rented trucks', 'fleet', $monthly->concat($shared)->values()->all());
    }

    /** @return array<string, mixed> */
    private function supplierBills(): array
    {
        $lines = $this->billsOwedAsOf(now())
            ->map(static fn (array $bill): array => [
                'id' => $bill['invoice']->getKey(),
                'name' => $bill['invoice']->payee ?? $bill['invoice']->number,
                'detail' => $bill['invoice']->number,
                'amount_cents' => $bill['owed_cents'],
                'in_flight_cents' => 0,
                'due_on' => $bill['invoice']->due_at?->toDateString(),
                'settle_at' => '/billing',
            ])
            ->values()
            ->all();

        return $this->group('supplier_bills', 'Supplier bills', 'billing', $lines);
    }

    /** @return array<string, mixed> */
    private function payrollOwed(): array
    {
        $lines = array_map(static fn (array $line): array => [
            'id' => $line['id'],
            'name' => $line['name'],
            'detail' => $line['detail'],
            'amount_cents' => $line['amount_cents'],
            'in_flight_cents' => 0,
            'due_on' => $line['due_on'],
            'settle_at' => $line['settle_at'] ?? '/payroll',
        ], $this->payroll->owedAsOf(now()));

        return $this->group('payroll', 'Payroll', 'badge', $lines);
    }

    /** @return array<string, mixed> */
    private function otherSpend(): array
    {
        $lines = Expense::query()
            ->with('category:id,name')
            ->whereHas('category', static fn ($query) => $query->where('key', '!=', TruckRentService::CATEGORY_KEY))
            ->where('status', StatusValue::Pending->value)
            ->orderBy('date')
            ->get()
            ->map(static fn (Expense $expense): array => [
                'id' => $expense->getKey(),
                'name' => $expense->payee ?? $expense->category?->name ?? 'Expense',
                'detail' => $expense->note ?? $expense->category?->name ?? '',
                'amount_cents' => (int) $expense->amount_cents,
                'in_flight_cents' => 0,
                'due_on' => $expense->date?->toDateString(),
                'settle_at' => '/expenses',
            ])
            ->all();

        return $this->group('other', 'Other spend', 'wallet', $lines);
    }

    /** @return array<string, string> */
    private function arrangementsByPartner(): array
    {
        return Vehicle::query()
            ->whereNotNull('owner_trucker_id')
            ->get(['owner_trucker_id', 'plate', 'wheels', 'arrangement'])
            ->mapWithKeys(static function (Vehicle $vehicle): array {
                $terms = $vehicle->terms();

                $what = match ($terms) {
                    VehicleArrangement::SubContracted => 'Sub-contractor',
                    VehicleArrangement::RentedShare => $vehicle->wheels === null
                        ? 'Rented truck owner'
                        : "Rented {$vehicle->wheels}-wheeler owner",
                    default => 'Truck owner',
                };

                return [(string) $vehicle->owner_trucker_id => "{$what} · {$vehicle->plate}"];
            })
            ->all();
    }

    /** @return array<string, array<int, Vehicle>> */
    private function shareRentalsByOwner(): array
    {
        return Vehicle::query()
            ->with('ownerPartner:id,name')
            ->where('arrangement', VehicleArrangement::RentedShare->value)
            ->whereNotNull('owner_trucker_id')
            ->orderBy('plate')
            ->get()
            ->groupBy('owner_trucker_id')
            ->map(static fn ($trucks): array => $trucks->all())
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function group(string $key, string $label, string $icon, array $lines): array
    {
        $today = now()->toDateString();
        $lines = array_map(static fn (array $line): array => [
            ...$line,
            'overdue' => ($line['due_on'] ?? null) !== null && $line['due_on'] < $today,
        ], $lines);

        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'count' => count($lines),
            'total_cents' => array_sum(array_column($lines, 'amount_cents')),
            'in_flight_cents' => array_sum(array_column($lines, 'in_flight_cents')),
            'overdue_count' => count(array_filter(array_column($lines, 'overdue'))),
            'lines' => $lines,
        ];
    }
}
