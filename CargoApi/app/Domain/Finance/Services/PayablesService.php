<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Repositories\InvoiceRepository;
use App\Domain\Finance\Models\Expense;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Services\TruckRentService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything the fleet owes, in one list.
 *
 * The money going out had grown into four places that never met: a partner's
 * wallet, a hired truck's monthly rent, a supplier's bill and whatever else
 * somebody filed as spend. Each screen was right about its own corner and
 * nobody could answer "what do we owe this week" without opening all four and
 * adding up.
 *
 * ## Read-only, on purpose
 *
 * Nothing is settled here. Every line links to the screen that owns it —
 * Truckers, Billing, Expenses — and is settled there, under that module's own
 * permission.
 *
 * That is not timidity about scope. Settling a partner's wallet means picking
 * which runs a payment covers; settling a supplier bill means allocating
 * against an invoice; marking an expense paid is a third thing again. Putting
 * three different settle flows behind one button would either flatten
 * distinctions that matter or grow into three forms on one page. A roll-up
 * that tells you where to go is the honest shape.
 *
 * It also keeps the permissions straight. This page is `finance.view` — a
 * money overview — and it can show a figure without granting the right to
 * move it, which `truckers.manage` and `billing.manage` still gate.
 *
 * ## Why truckers are three rows and not one group
 *
 * A partner trucker, a ten-wheeler's owner and a sub-contractor are all
 * `truckers` rows with wallets, which is what makes the money work. But they
 * are three different arrangements to the person paying, and a list that
 * called them all "trucker" would be hiding the thing the reader is trying to
 * decide about. Each line says which it is, worked out from the truck that
 * names them.
 */
class PayablesService
{
    public function __construct(private readonly InvoiceRepository $invoices) {}

    /**
     * The whole picture: a total, and the groups that make it up.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $groups = [
            $this->truckers(),
            $this->rentedTrucks(),
            $this->supplierBills(),
            $this->otherSpend(),
        ];

        return [
            'total_cents' => array_sum(array_column($groups, 'total_cents')),
            /**
             * Money already sent that has not landed.
             *
             * Reported at the top rather than inside the truckers group,
             * because the question it answers is about the week rather than
             * about one partner: how much of what we owe is already out of the
             * door. Only partner payments have this state — a supplier bill is
             * paid or it is not.
             */
            'in_flight_cents' => array_sum(array_column($groups, 'in_flight_cents')),
            'groups' => $groups,
        ];
    }

    /**
     * One figure: everything still owed, as at a date.
     *
     * `overview()` answers "what do we owe" for the screen that lists it, and
     * it answers it about **today**. A period report needs the same question
     * asked about the end of its own window — otherwise looking back at the
     * first quarter would show it burdened with bills raised in September, and
     * the "actual income" beneath it would be a figure about no period at all.
     *
     * The same four kinds of debt, each wound back to the date:
     *
     *   a partner's wallet, from the entries that had landed by then;
     *   rent charged on a hired truck, and anything else filed as spend, from
     *   the rows dated on or before it and still unsettled;
     *   a supplier's bill, from what it was raised for less what had been paid
     *   against it by then.
     *
     * **One approximation, stated rather than hidden.** An expense records no
     * date of settlement — only that it is settled now — so a bill paid last
     * week is not counted as outstanding in a quarter that closed before it. It
     * reads "what, of what we owe today, was already owed then", which is exact
     * for the period in progress and understates a closed one. A supplier
     * invoice, which does carry its payments and their dates, is exact in both.
     */
    public function outstandingAsOf(CarbonInterface $asOf): int
    {
        return $this->truckersOwedAsOf($asOf)
            + $this->spendUnsettledAsOf($asOf)
            + $this->billsUnpaidAsOf($asOf);
    }

    /** Partner wallets in credit, counting only what had landed by the date. */
    private function truckersOwedAsOf(CarbonInterface $asOf): int
    {
        return (int) WalletEntry::query()
            ->landed()
            ->whereDate('occurred_on', '<=', $asOf->toDateString())
            ->selectRaw('trucker_id, sum(amount_cents) as balance')
            ->groupBy('trucker_id')
            ->pluck('balance')
            // A negative balance is the partner owing the fleet, which belongs
            // on a receivables list rather than being netted off this one.
            ->filter(static fn ($balance): bool => (int) $balance > 0)
            ->sum();
    }

    /** Rent and everything else filed as spend, dated by then and unsettled. */
    private function spendUnsettledAsOf(CarbonInterface $asOf): int
    {
        return (int) Expense::query()
            ->where('status', StatusValue::Pending->value)
            ->whereDate('date', '<=', $asOf->toDateString())
            ->sum('amount_cents');
    }

    /** Supplier bills raised by the date, less what had been paid by it. */
    private function billsUnpaidAsOf(CarbonInterface $asOf): int
    {
        $date = $asOf->toDateString();

        return (int) Invoice::query()
            ->where('direction', InvoiceDirection::Payable->value)
            ->whereDate('issued_at', '<=', $date)
            ->withSum(
                ['allocations as paid_by_then' => static fn (Builder $query) => $query
                    ->whereHas('payment', static fn (Builder $payment) => $payment
                        ->whereDate('paid_on', '<=', $date))],
                'amount_cents',
            )
            ->get()
            // Floored per bill, so an over-allocated one does not lend its
            // overpayment to the next bill's balance.
            ->sum(static fn (Invoice $invoice): int => max(
                0,
                (int) $invoice->amount_cents - (int) ($invoice->paid_by_then ?? 0),
            ));
    }

    /**
     * What the fleet owes the people who haul for it.
     *
     * A partner is here when their wallet balance is positive — the fleet
     * collected a customer's money and owes their share onwards. A negative
     * balance is the other direction and belongs on a receivables list, not
     * this one, so it is left out rather than shown as a negative payable.
     *
     * @return array<string, mixed>
     */
    private function truckers(): array
    {
        $partners = Trucker::query()->orderBy('name')->get();

        if ($partners->isEmpty()) {
            return $this->group('truckers', 'Truckers', 'fleet', []);
        }

        // One read for every partner's arrangement, rather than a query per
        // row: the fleet screen asks the same question and this is the same
        // answer, keyed by who is owed.
        $byPartner = $this->arrangementsByPartner();

        $lines = $partners
            ->map(function (Trucker $trucker) use ($byPartner): ?array {
                $balance = WalletEntry::balanceFor($trucker->getKey());

                if ($balance <= 0) {
                    return null;
                }

                $inFlight = WalletEntry::inFlightFor($trucker->getKey());

                return [
                    'id' => $trucker->getKey(),
                    'name' => $trucker->name,
                    // Which kind of arrangement, because "trucker" covers
                    // three of them and the reader is deciding between them.
                    'detail' => $byPartner[$trucker->getKey()] ?? 'Partner trucker',
                    'amount_cents' => $balance,
                    'in_flight_cents' => $inFlight,
                    'due_on' => null,
                    // Where it is settled. The page links rather than settles.
                    'settle_at' => '/truckers',
                ];
            })
            ->filter()
            ->values()
            ->all();

        return $this->group('truckers', 'Truckers', 'fleet', $lines);
    }

    /**
     * Rent on trucks hired at a flat monthly fee.
     *
     * The charges `cargo:truck-rent` raises, still unpaid. Not the terms on
     * the vehicle — a truck rented at ₱50,000 a month is not owed ₱50,000
     * forever, it is owed for the months that have been billed and not
     * settled.
     *
     * @return array<string, mixed>
     */
    private function rentedTrucks(): array
    {
        $lines = Expense::query()
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
            ])
            ->all();

        return $this->group('rented_trucks', 'Rented trucks', 'fleet', $lines);
    }

    /**
     * Bills from suppliers — invoices raised against the fleet.
     *
     * @return array<string, mixed>
     */
    private function supplierBills(): array
    {
        $lines = $this->invoices->outstandingInvoices(InvoiceDirection::Payable)
            ->map(static function ($invoice): array {
                $paid = (int) ($invoice->allocations_sum_amount_cents ?? 0);

                return [
                    'id' => $invoice->getKey(),
                    'name' => $invoice->payee ?? $invoice->number,
                    'detail' => $invoice->number,
                    // What is left, not what was billed: a part-paid bill owes
                    // the remainder and showing the face value would overstate
                    // the week by everything already settled.
                    'amount_cents' => (int) $invoice->amount_cents - $paid,
                    'in_flight_cents' => 0,
                    'due_on' => $invoice->due_at?->toDateString(),
                    'settle_at' => '/billing',
                ];
            })
            ->filter(static fn (array $line): bool => $line['amount_cents'] > 0)
            ->values()
            ->all();

        return $this->group('supplier_bills', 'Supplier bills', 'billing', $lines);
    }

    /**
     * Everything else filed as spend and not yet paid.
     *
     * The rent charges are excluded because they have a group of their own
     * above — counted twice, the total would be wrong in the one figure this
     * page exists to get right.
     *
     * @return array<string, mixed>
     */
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

    /**
     * Which arrangement each partner is owed under, keyed by partner.
     *
     * A partner named by a hired truck is that truck's owner or operator; one
     * named by nothing is an ordinary partner hauling their own work. The
     * plate rides along because "Delfin Uy · ten-wheeler ABC-1234" is what
     * somebody writing a cheque wants to see.
     *
     * @return array<string, string>
     */
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

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function group(string $key, string $label, string $icon, array $lines): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'count' => count($lines),
            'total_cents' => array_sum(array_column($lines, 'amount_cents')),
            'in_flight_cents' => array_sum(array_column($lines, 'in_flight_cents')),
            'lines' => $lines,
        ];
    }
}
