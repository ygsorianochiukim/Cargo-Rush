<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Services;

use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\Truck;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class TruckRentService
{
    public const CATEGORY_KEY = 'truck-rental';

    public function chargeMonth(CarbonInterface $on): int
    {
        $month = $on->copy()->startOfMonth();
        $category = $this->category();
        $raised = 0;

        $hired = Vehicle::query()
            ->where('arrangement', VehicleArrangement::Rented->value)
            ->whereNotNull('rent_cents')
            ->where('rent_cents', '>', 0)
            ->get();

        foreach ($hired as $vehicle) {
            if ($this->charge($vehicle, $month, $category) !== null) {
                $raised++;
            }
        }

        return $raised;
    }

    public function chargeDue(CarbonInterface $today): int
    {
        $category = $this->category();
        $raised = 0;

        $hired = Vehicle::query()
            ->where('arrangement', VehicleArrangement::Rented->value)
            ->whereNotNull('rent_cents')
            ->where('rent_cents', '>', 0)
            ->get();

        foreach ($hired as $vehicle) {
            $raised += $this->chargeDueFor($vehicle, $today, $category);
        }

        return $raised;
    }

    public function chargeDueFor(Vehicle $vehicle, CarbonInterface $today, ?ExpenseCategory $category = null): int
    {
        if (! $vehicle->chargesRent()) {
            return 0;
        }

        $current = $today->copy()->startOfMonth();
        $since = ($vehicle->created_at ?? $today)->copy()->startOfMonth();
        $raised = 0;

        foreach ([$current->copy()->subMonth(), $current] as $month) {
            if ($month->lt($since)) {
                continue;
            }

            if ($this->charge($vehicle, $month, $category) !== null) {
                $raised++;
            }
        }

        return $raised;
    }

    public function charge(Vehicle $vehicle, CarbonInterface $month, ?ExpenseCategory $category = null): ?Expense
    {
        if (! $vehicle->chargesRent()) {
            return null;
        }

        $period = $month->copy()->startOfMonth();

        $existing = Expense::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->whereHas('category', static fn ($query) => $query->where('key', self::CATEGORY_KEY))
            ->whereDate('date', $period->copy()->endOfMonth()->toDateString())
            ->exists();

        if ($existing) {
            return null;
        }

        $reference = sprintf('RENT %s %s', $vehicle->plate ?? 'UNPLATED', $period->format('Y-m'));

        return DB::transaction(fn (): Expense => Expense::create([
            'category_id' => ($category ?? $this->category())->getKey(),
            'truck_id' => $this->ledgerTruckFor($vehicle),
            'vehicle_id' => $vehicle->getKey(),
            'date' => $period->copy()->endOfMonth()->toDateString(),
            'amount_cents' => (int) $vehicle->rent_cents,
            'currency' => 'PHP',
            'payee' => $vehicle->owner_name,
            'reference' => $reference,
            'note' => sprintf('Monthly rent for %s', $period->format('F Y')),
            'status' => StatusValue::Pending->value,
        ]));
    }

    private function ledgerTruckFor(Vehicle $vehicle): ?string
    {
        return Truck::query()->where('vehicle_id', $vehicle->getKey())->value('id');
    }

    private function category(): ExpenseCategory
    {
        return ExpenseCategory::firstOrCreate(['key' => self::CATEGORY_KEY], [
            'name' => 'Truck Rental',
            'description' => 'Monthly fee for a truck the fleet hires at a flat rate.',
            'icon' => 'fleet',
            'position' => 25,
        ]);
    }
}
