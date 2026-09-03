<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Seeder;

/**
 * "v3 Cargorush Master Dashboard 2026.xlsx", transcribed.
 *
 * Amounts are the workbook's pesos in centavos. These rows reproduce every
 * aggregate the workbook itself prints, so `finance.spec.ts` on the web side
 * and a roll-up from this API have to agree — if they ever do not, one of the
 * two transcriptions has drifted.
 *
 * Trucks 7 and 8 exist with no plate and no rows. They are not an oversight:
 * DESIGN.md section 5.1 requires an unassigned unit to render as "Unassigned"
 * rather than be filtered away.
 */
class LedgerSeeder extends Seeder
{
    private const TRUCKS = [
        ['Truck 1', 'MAR1390'],
        ['Truck 2', 'CBS8862'],
        ['Truck 3', 'LAQ8325'],
        ['Truck 4', 'CCE7342'],
        ['Truck 5', 'CCP9548'],
        ['Truck 6', 'CDF5211'],
        ['Truck 7', null],
        ['Truck 8', null],
    ];

    public function run(): void
    {
        $vehicles = Vehicle::query()->pluck('id', 'plate');

        foreach (self::TRUCKS as $i => [$label, $plate]) {
            Truck::updateOrCreate(['label' => $label], [
                'plate' => $plate,
                'vehicle_id' => $plate === null ? null : ($vehicles[$plate] ?? null),
                'position' => $i + 1,
            ]);
        }

        $trucks = Truck::query()->pluck('id', 'label');

        foreach ($this->rows() as $label => $rows) {
            foreach ($rows as $row) {
                $this->entry($trucks[$label], $row);
            }
        }
    }

    /**
     * date, income, fuel, driver, helper, maintenance, allowance, route, remarks
     *
     * @param  array<int, mixed>  $row
     */
    private function entry(string $truckId, array $row): void
    {
        // The workbook's pesos, to centavos. Rounded because 31540.68 has to
        // land on exactly 3_154_068 and not one centavo either side of it.
        $c = static fn (float $peso): int => (int) round($peso * 100);

        LedgerEntry::updateOrCreate(
            ['truck_id' => $truckId, 'date' => $row[0]],
            [
                'trip_income_cents' => $c($row[1]),
                'fuel_cents' => $c($row[2]),
                'driver_salary_cents' => $c($row[3]),
                'helper_salary_cents' => $c($row[4]),
                'maintenance_cents' => $c($row[5]),
                'allowance_cents' => $c($row[6]),
                'route' => $row[7],
                'remarks' => $row[8] ?? null,
            ],
        );
    }

    /**
     * One sheet per truck, exactly as the workbook keeps them.
     *
     * @return array<string, array<int, array<int, mixed>>>
     */
    private function rows(): array
    {
        return [
            'Truck 1' => [
                ['2026-03-26', 30721, 0, 1500, 2000, 0, 1000, 'Pagadian Warehouse'],
                ['2026-03-27', 30721, 0, 1500, 2000, 0, 1200, null],
                ['2026-03-28', 31540.68, 0, 1500, 2000, 0, 1200, 'Maranding, Lala'],
                ['2026-03-29', 0, 0, 1500, 2000, 0, 1300, 'Dologon'],
                ['2026-03-30', 0, 0, 1500, 2000, 0, 0, null],
                ['2026-04-06', 30721, 0, 1500, 2000, 0, 1200, 'Maranding'],
                ['2026-04-07', 30721, 0, 1500, 2000, 0, 1200, 'Maranding'],
                ['2026-04-08', 31540.68, 0, 1500, 2000, 0, 1200, 'Maranding'],
                ['2026-04-09', 0, 0, 1500, 2000, 0, 1200, 'Dologon'],
            ],
            'Truck 2' => [
                ['2026-03-26', 22517, 0, 1200, 1600, 0, 1200, 'Butuan'],
                ['2026-03-27', 10354, 0, 1000, 1400, 0, 1000, 'Gingoog'],
                ['2026-03-28', 10354, 9061.01, 1000, 1400, 0, 800, 'Gingoog'],
                ['2026-03-29', 15397, 9481.95, 1000, 1400, 1000, 1300, 'Kulambugan'],
                ['2026-03-30', 22517, 0, 1200, 1600, 0, 1100, 'Butuan'],
                ['2026-03-31', 9000, 0, 1000, 1400, 0, 1000, 'Special'],
                ['2026-04-01', 10354, 0, 1000, 1400, 0, 1000, 'Dalipuga'],
                ['2026-04-06', 15397.07, 0, 1000, 1400, 0, 1000, 'Dologon'],
                ['2026-04-07', 22517, 0, 1200, 1600, 0, 1200, 'Ozamis'],
                ['2026-04-08', 26824, 0, 1200, 1600, 0, 1200, 'Pagadian'],
                ['2026-04-09', 10354, 0, 1000, 1400, 0, 1000, 'Gingoog'],
                ['2026-04-10', 10354, 0, 1000, 1400, 0, 1000, 'Baloi'],
                ['2026-04-11', 24267, 0, 1200, 1600, 0, 1200, 'Jimenez'],
                ['2026-04-12', 27247, 0, 1200, 1600, 0, 1200, 'Pagadian'],
                ['2026-04-13', 10354, 0, 1000, 1400, 0, 1000, 'Gingoog'],
            ],
            // Running at a loss over this period, and it must read as one.
            'Truck 3' => [
                ['2026-03-26', 0, 5002.61, 900, 1400, 0, 400, 'San Fernando'],
                ['2026-03-27', 0, 0, 0, 0, 0, 0, 'Ozamis'],
                ['2026-03-28', 0, 3329.74, 0, 0, 0, 0, 'Pagadian'],
                ['2026-03-29', 0, 0, 0, 0, 0, 0, 'Gingoog'],
                ['2026-03-30', 0, 0, 0, 0, 0, 1000, 'Gingoog'],
                ['2026-03-31', 0, 0, 0, 0, 0, 0, 'Gingoog'],
                ['2026-04-01', 0, 0, 0, 0, 0, 0, 'Pagadian'],
                ['2026-04-02', 0, 0, 0, 0, 0, 0, 'Butuan'],
            ],
            'Truck 4' => [
                ['2026-03-29', 10354, 6297.23, 1000, 1400, 0, 800, 'Gingoog'],
                ['2026-03-30', 0, 0, 1000, 1400, 0, 800, 'Impasug-ong'],
                ['2026-03-31', 10354, 11801.69, 1000, 1400, 0, 1000, 'Gingoog'],
                ['2026-04-01', 0, 0, 0, 0, 0, 0, 'Pagadian'],
                ['2026-04-02', 0, 0, 1200, 1600, 0, 1000, 'Butuan'],
                ['2026-04-06', 0, 0, 0, 0, 0, 0, 'Dologon'],
                ['2026-04-07', 0, 0, 0, 0, 0, 0, 'Ozamis'],
                ['2026-04-08', 0, 0, 0, 0, 0, 0, 'Pagadian'],
                ['2026-04-09', 0, 0, 0, 0, 0, 0, 'Gingoog'],
                ['2026-04-10', 0, 0, 0, 0, 0, 0, 'Baloi'],
                ['2026-04-11', 0, 0, 0, 0, 0, 0, 'Jimenez'],
                ['2026-04-12', 0, 0, 0, 0, 0, 0, 'Pagadian'],
                ['2026-04-13', 0, 0, 1200, 1600, 0, 1000, 'Gingoog'],
            ],
            'Truck 5' => [
                ['2026-03-26', 26824, 0, 1200, 1600, 0, 1200, null, 'H'],
                ['2026-03-27', 22517, 13084.43, 1200, 1600, 0, 1200, 'Tiguma, Pagadian', 'G'],
                ['2026-03-28', 26824, 0, 1200, 1600, 0, 1200, 'Gingoog', 'H'],
                ['2026-03-29', 26824, 0, 1200, 1600, 0, 1200, 'Jimenez', 'H'],
                ['2026-04-06', 0, 0, 0, 0, 0, 0, 'Dologon'],
                ['2026-04-07', 0, 0, 0, 0, 0, 0, 'Ozamis'],
                ['2026-04-08', 0, 0, 0, 0, 0, 0, 'Pagadian'],
                ['2026-04-09', 0, 0, 0, 0, 0, 0, 'Gingoog'],
                ['2026-04-10', 0, 0, 0, 0, 0, 0, 'Baloi'],
                ['2026-04-11', 0, 0, 0, 0, 0, 0, 'Jimenez'],
                ['2026-04-12', 0, 0, 0, 0, 0, 0, 'Pagadian'],
                ['2026-04-13', 0, 0, 0, 0, 0, 0, 'Gingoog'],
            ],
            // Scheduled, but nothing recorded yet — a real state, not a gap.
            'Truck 6' => [
                ['2026-03-26', 0, 0, 0, 0, 0, 0, 'Dologon'],
                ['2026-03-27', 0, 0, 0, 0, 0, 0, 'Ozamis'],
                ['2026-03-28', 0, 0, 0, 0, 0, 0, 'Pagadian'],
                ['2026-03-29', 0, 0, 0, 0, 0, 0, 'Gingoog'],
                ['2026-03-30', 0, 0, 0, 0, 0, 0, 'Baloi'],
                ['2026-03-31', 0, 0, 0, 0, 0, 0, 'Jimenez'],
                ['2026-04-01', 0, 0, 0, 0, 0, 0, 'Pagadian'],
                ['2026-04-02', 0, 0, 0, 0, 0, 0, 'Gingoog'],
                ['2026-04-06', 0, 0, 0, 0, 0, 0, 'Dologon'],
            ],
        ];
    }
}
