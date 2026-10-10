<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Models\TruckCategory;
use Illuminate\Database\Seeder;

class SubsidyRateCardSeeder extends Seeder
{
    private const DIESEL_BASELINE_CENTS = 4_300;

    private const SOURCE = 'CDI-CO-DY-OD-280-0001-8 · CDO dry, 28 cbm, 2026';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private const CLASSES = [
        ['brand-new-truck', 'Brand New Truck', 'A unit bought new. Priced above the standing fleet.'],
        ['recon-truck', 'Recon Truck', 'A reconditioned unit. Priced between new and the standing fleet.'],
    ];

    /** @var array<int, array{0: string, 1: int, 2: int, 3: int, 4: int, 5: int, 6: int}> */
    private const BANDS = [
        ['A1',   1,  40,  4_165,  4_916,  4_561,  14],
        ['A2',   1,  40,  4_412,  5_213,  4_833,  19],
        ['B',   41,  80,  5_230,  6_201,  5_744,  28],
        ['C',   81, 120,  6_530,  7_845,  7_187,  42],
        ['D',  121, 160,  7_803,  9_090,  8_446,  56],
        ['E1', 161, 200, 11_922, 14_330, 13_126,  70],
        ['E2', 161, 200, 14_569, 17_530, 16_050,  75],
        ['F',  201, 240, 17_210, 20_151, 18_680,  84],
        ['G',  241, 280, 17_766, 20_797, 19_281,  98],
        ['H',  281, 320, 20_197, 23_620, 21_908, 112],
        ['I',  321, 360, 22_626, 26_442, 24_534, 126],
        ['J',  361, 400, 25_056, 29_265, 27_161, 140],
        ['K',  401, 440, 27_486, 32_087, 29_787, 154],
        ['L',  441, 480, 29_916, 34_910, 32_413, 168],
        ['M',  481, 520, 32_346, 37_732, 35_039, 182],
        ['N',  521, 560, 34_776, 40_555, 37_665, 196],
        ['O',  561, 600, 37_206, 43_377, 40_292, 210],
    ];

    public function run(): void
    {
        $classes = $this->classes();

        foreach (self::BANDS as $position => [$code, $from, $to, $current, $brandNew, $recon, $step]) {
            $zone = PricingZone::updateOrCreate(
                ['code' => $code],
                [
                    'name' => "Zone {$code}",
                    'min_km' => $position === 0 ? 0 : $from,
                    'max_km' => $to + 1,
                    'diesel_baseline_cents' => self::DIESEL_BASELINE_CENTS,
                    'position' => $position,
                    'status' => 'active',
                    'notes' => self::SOURCE,
                ],
            );

            $this->line($zone, null, 'Current fleet', $current, $step, 0);
            $this->line($zone, $classes['brand-new-truck'], 'Brand new truck', $brandNew, $step, 1);
            $this->line($zone, $classes['recon-truck'], 'Recon truck', $recon, $step, 2);
        }
    }

    /** @return array<string, string> */
    private function classes(): array
    {
        $ids = [];

        foreach (self::CLASSES as $offset => [$key, $name, $description]) {
            $ids[$key] = TruckCategory::updateOrCreate(
                ['key' => $key],
                [
                    'name' => $name,
                    'description' => $description,
                    'position' => 100 + $offset,
                ],
            )->id;
        }

        return $ids;
    }

    private function line(
        PricingZone $zone,
        ?string $truckCategoryId,
        string $label,
        int $pesos,
        int $stepPesos,
        int $position,
    ): void {
        PricingBracket::updateOrCreate(
            ['zone_id' => $zone->id, 'truck_category_id' => $truckCategoryId],
            [
                'label' => $label,
                'min_km' => null,
                'max_km' => null,
                'base_cents' => $pesos * 100,
                'per_km_cents' => 0,
                'per_kg_cents' => 0,
                'minimum_cents' => 0,
                'diesel_step_cents' => $stepPesos * 100,
                'position' => $position,
            ],
        );
    }
}
