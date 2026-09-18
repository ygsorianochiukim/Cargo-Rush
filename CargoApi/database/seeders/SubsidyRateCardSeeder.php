<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Pricing\Models\PricingBracket;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Models\TruckCategory;
use Illuminate\Database\Seeder;

/**
 * The subsidy table, as a rate card.
 *
 * Transcribed from *SUBSIDY TABLE 2024_CDO 28cbm* — CDO dry truck rates for a
 * 28 cbm load, document CDI-CO-DY-OD-280-0001-8, implementation year 2026. Its
 * three visible sheets are the same seventeen bands at three sets of money,
 * one per condition of unit: the fleet as it stands, brand new, and recon.
 *
 *     Zone   Distance          Current   Brand new    Recon   per ₱1/L
 *     A1     1   to    40        4,165       4,916     4,561       14
 *     A2     1   to    40        4,412       5,213     4,833       19
 *     B      41  to    80        5,230       6,201     5,744       28
 *     …
 *     O      561 to   600       37,206      43,377    40,292      210
 *
 * ## Not part of provisioning, and that is the point
 *
 * `TruckCategorySeeder` runs for every company that registers, because a firm
 * with no unit types has a form it cannot fill in. This does not: it is one
 * haulier's negotiated rates, published by its principal, and laying it on
 * every company that signs up would be handing them somebody else's prices.
 *
 *     php artisan db:seed --class=Database\\Seeders\\SubsidyRateCardSeeder
 *
 * run inside the company that works from this table. Everything is keyed on
 * the zone code, so running it twice corrects the figures rather than doubling
 * the card — which is what the next revision of the document will need.
 *
 * ## How a row becomes rows
 *
 * The **band** is the zone: code, name, and the kilometres half-open, so the
 * table's "1 to 40" is stored as [1, 41) and 41 km belongs to band B alone.
 *
 * A1's band starts at **0**, not 1. The table starts at 1 because a haul of no
 * distance is not a haul, but a trip booked over the phone against a town name
 * carries no distance at all until somebody pins it — and the shortest
 * published band is a better answer for it than falling through to the config
 * tariff at an unrelated figure.
 *
 * The **money** is three lines under each band. The `Current Price` column has
 * no truck category on it, because it is the rate for the fleet as it stands
 * and a booking that asks for nothing in particular should get it. Brand new
 * and recon name a category each, and `BracketResolver` takes the more
 * specific line when a booking asks for one.
 *
 * Every band's baseline is ₱43.00/L — the top of the "From 30 / To 43 per
 * litre diesel" band the printed prices cover — so diesel anywhere inside that
 * band adds nothing and each peso above it adds the band's step. At ₱85/L,
 * band A1 is 4,165 + 14 × 42 = ₱4,753, which is what the table's ₱85 column
 * prints. `SubsidyRateCardTest` pins that against the published figures.
 *
 * ## What the card does not say
 *
 * The table stops at 600 km, and so does this. A longer run finds no band,
 * falls to the firm's plain distance card if it has one and to the config
 * tariff if it does not, and the quote's `source` says `tariff` — visibly
 * off-card, which is the right answer for a distance the principal has not
 * published a rate for.
 *
 * A1 and A2 share a band, as do E1 and E2. Nothing in the document says what
 * separates them, and nothing here guesses: both are active, the desk picks
 * per trip, and a trip with no pick gets the lower of the two. See
 * `ZoneResolver`.
 */
class SubsidyRateCardSeeder extends Seeder
{
    /** The pump price the printed figures already cover, to the centavo. */
    private const DIESEL_BASELINE_CENTS = 4_300;

    /**
     * The document's own reference, onto every band it created.
     *
     * So that somebody looking at a band in the editor three revisions later
     * can tell which sheet of paper it came off, rather than having to ask.
     */
    private const SOURCE = 'CDI-CO-DY-OD-280-0001-8 · CDO dry, 28 cbm, 2026';

    /**
     * The classes of unit the table prices beside the fleet rate.
     *
     * key, name, description
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const CLASSES = [
        ['brand-new-truck', 'Brand New Truck', 'A unit bought new. Priced above the standing fleet.'],
        ['recon-truck', 'Recon Truck', 'A reconditioned unit. Priced between new and the standing fleet.'],
    ];

    /**
     * The table, in pesos exactly as printed.
     *
     * code, from km, to km (inclusive), current, brand new, recon, step per ₱1/L
     *
     * Pesos rather than centavos, and converted on the way in, so that every
     * figure here can be read straight off the sheet and checked against it.
     * A transcription is only as good as somebody's ability to proof it, and
     * `416500` is not a number anybody can find on a printed page.
     *
     * @var array<int, array{0: string, 1: int, 2: int, 3: int, 4: int, 5: int, 6: int}>
     */
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
                    // The first band starts at zero so an unpinned trip has a
                    // published rate rather than no band at all.
                    'min_km' => $position === 0 ? 0 : $from,
                    // Half-open: the table's inclusive "to 40" is the first
                    // kilometre the next band owns.
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

    /**
     * The classes of unit the table needs, created if the firm has not got
     * them.
     *
     * `updateOrCreate` on the key, like `TruckCategorySeeder`, so a firm that
     * already named one of these keeps its id — and every rate-card line and
     * every vehicle pointing at it keeps pointing at it.
     *
     * @return array<string, string> key => id
     */
    private function classes(): array
    {
        $ids = [];

        foreach (self::CLASSES as $offset => [$key, $name, $description]) {
            $ids[$key] = TruckCategory::updateOrCreate(
                ['key' => $key],
                [
                    'name' => $name,
                    'description' => $description,
                    // After whatever the firm already has, so seeding a card
                    // does not reorder a list somebody arranged.
                    'position' => 100 + $offset,
                ],
            )->id;
        }

        return $ids;
    }

    /**
     * One line of money under a band.
     *
     * No kilometres: the band belongs to the zone, and a line that carried its
     * own copy would be a second place for 1–40 km to be written down and
     * therefore a second place for it to be wrong.
     *
     * Matched on the band and the class of unit rather than created blind, so
     * a second run corrects a figure in place. A line's id is on every trip it
     * ever priced, and dropping and recreating the card would orphan that
     * trace on every revision of the document.
     */
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
                // The band accounts for the distance — that is what banding it
                // is for — so charging per kilometre on top would bill the
                // same run twice. Weight likewise: the table is a flat rate
                // for a 28 cbm load, not a rate per kilo.
                'per_km_cents' => 0,
                'per_kg_cents' => 0,
                'minimum_cents' => 0,
                'diesel_step_cents' => $stepPesos * 100,
                'position' => $position,
            ],
        );
    }
}
