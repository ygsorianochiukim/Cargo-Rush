<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\DTO\ProofData;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trip\Services\TripService;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\TruckerVehicle;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Trucker\Services\WalletService;
use Database\Seeders\Concerns\AdoptsTrashedRows;
use Database\Seeders\Concerns\SeedsIntoACompany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * The sub-contractors: partners hauling in their own trucks.
 *
 *     php artisan db:seed --class="Database\Seeders\Demo\PartnerSeeder"
 *
 * `FleetSeeder` fills the company's own side — its drivers, its units, its
 * customers. This is the other half of the roster: the people the desk brokers
 * work to, who own the wheels they turn up in, and whose money lives in a
 * wallet rather than on a payslip.
 *
 * ## Every state the Truckers screen has to render
 *
 * A demo with five approved partners proves nothing about the screen, because
 * the screen's whole job is telling them apart. So each row here is a different
 * answer to the two questions the office asks:
 *
 *   **Has the office cleared them?** — `pending` is a registration nobody has
 *   read yet (and the badge on the sidebar counts them), `active` is vetted,
 *   `inactive` is somebody the haulier has stopped working with.
 *
 *   **Are they switched on?** — the handset's own toggle, which says nothing
 *   about trust. A vetted partner with the switch off takes no work either.
 *
 * Only a partner who is vetted, online *and* holding a truck can be given a
 * load — `Trucker::canTakeWork()` — so the roster below deliberately breaks
 * that in three different places.
 *
 * ## The wallet is seeded by settling real runs
 *
 * Not one invented balance. The delivered runs are closed through
 * `TripService::complete()`, exactly as the driver's handset closes one, and
 * the wallet rows are whatever that produced. A balance typed into a fixture
 * would agree with nothing and would keep agreeing with nothing after the
 * commission rate changed.
 *
 * That also seeds both directions of the debt, which is the part of the wallet
 * worth looking at:
 *
 *   A `cargo_rush` run — the desk found the work and invoiced the customer, so
 *   the haulier owes the partner their share. **Positive.**
 *
 *   A `direct` run — the customer went straight to the partner, who billed
 *   them, so the partner owes the haulier its cut. **Negative.**
 *
 * One partner below has both, which is the ordinary case and the reason there
 * is one account rather than a payable and a receivable that never meet.
 *
 * ## Idempotent
 *
 * Partners match on their phone, units on the plate, runs on the reference, and
 * every settlement checks for its own reference before writing. Running it
 * twice tops the data up rather than paying anybody twice.
 */
class PartnerSeeder extends Seeder
{
    use AdoptsTrashedRows, SeedsIntoACompany;

    /**
     * name, phone, licence, licence expiry, status, online, lat, lng, runs done
     *
     * The pins are real places around Cagayan de Oro at real distances from the
     * yard in Iponan, because the job board sorts by distance and five partners
     * pinned in one barangay would not show it doing anything.
     */
    private const PARTNERS = [
        ['Dennis Abella', '0917 555 0301', 'CDO-21-448190', '2028-05-14', 'active', true, 8.4772, 124.6450, 138],
        ['Marivic Ong', '0917 555 0302', 'BUK-19-220741', '2027-11-08', 'active', true, 8.1575, 125.1278, 92],
        // Vetted, and the switch is off. Not on the job board today, and the
        // office screen has to say why without calling him unapproved.
        ['Ronel Pacaldo', '0917 555 0303', 'MIS-20-771203', '2027-03-22', 'active', false, 8.2280, 124.2452, 64],
        // A registration that came in off a phone and nobody has read yet.
        // This is the row the sidebar's pending badge counts.
        ['Joel Mendez', '0917 555 0304', 'LAN-22-903318', '2029-01-30', 'pending', false, 8.4304, 124.6319, 0],
        // Somebody the haulier has stopped working with. Kept, not deleted:
        // their wallet and their history still have to be answerable for.
        ['Fely Dagondon', '0917 555 0305', 'CDO-18-110925', '2026-12-04', 'inactive', false, null, null, 41],
        /**
         * The sub-contracted operator, and the one partner with no truck of
         * their own here.
         *
         * His unit sits on the fleet's roster rather than in `trucker_vehicles`
         * — see `HiredFleetSeeder` — because a sub-contracted truck runs under
         * the fleet's name on the fleet's dispatch. He is a `truckers` row all
         * the same, because that is where the handset, the runs and the wallet
         * live.
         */
        ['Arnel Quibod', '0917 555 0306', 'CDO-21-556037', '2028-09-19', 'active', true, 8.4856, 124.5808, 57],
    ];

    /**
     * The partners' own trucks: owner phone, plate, model, kg, category, status.
     *
     * A partner with two units is deliberate — one of them is in the shop, and
     * `Trucker::activeVehicle()` picking the other is the behaviour a
     * walkthrough should be able to see rather than be told about.
     */
    private const UNITS = [
        ['0917 555 0301', 'CDO 8821', 'Isuzu Forward', 15000, 'dry-goods', 'available'],
        ['0917 555 0301', 'CDO 4417', 'Fuso Fighter', 8000, 'dry-goods', 'maintenance'],
        ['0917 555 0302', 'BUK 3390', 'Hino 500', 12000, 'freezer', 'available'],
        ['0917 555 0303', 'MIS 7028', 'UD Quester', 16000, 'flatbed', 'available'],
        // Registered with his application, and worth nothing to him until
        // somebody at the desk approves the man holding the keys.
        ['0917 555 0304', 'LAN 1174', 'Isuzu Giga', 14000, 'dry-goods', 'available'],
    ];

    /**
     * The runs. reference, partner phone, origin, destination, cargo, kg,
     * price in pesos, booking source, status, days from today.
     *
     * Prices are round figures rather than tariff quotes, and that is the
     * point: ₱120,000 at twelve per cent is ₱14,400 to the haulier and
     * ₱105,600 to the partner, and anybody checking the wallet can do that
     * arithmetic in their head instead of trusting it.
     */
    private const RUNS = [
        ['CR-24901', '0917 555 0301', 'Cagayan de Oro', 'Iligan', 'Bagged cement, 200 sacks', 12000, 120000, 'cargo_rush', 'delivered', -9],
        ['CR-24902', '0917 555 0301', 'Cagayan de Oro', 'Gingoog', 'Assorted hardware', 6400, 45000, 'direct', 'delivered', -6],
        ['CR-24903', '0917 555 0302', 'Valencia', 'Cagayan de Oro', 'Chilled dressed chicken', 9000, 86000, 'cargo_rush', 'delivered', -4],
        ['CR-24904', '0917 555 0302', 'Malaybalay', 'Cagayan de Oro', 'Sacked corn', 11000, 64000, 'cargo_rush', 'delivered', -2],
        // On the road now, so My Trips on the handset has a live one.
        ['CR-24905', '0917 555 0301', 'Cagayan de Oro', 'Ozamiz', 'Retail cartons', 5200, 52000, 'cargo_rush', 'in_transit', 0],
        // Nobody has taken this one. It is what the job board shows a partner
        // who opens the app with nothing on.
        ['CR-24906', null, 'Cagayan de Oro', 'Butuan', 'Dry goods, 14 pallets', 7800, 74000, 'cargo_rush', 'pending', 1],
        ['CR-24907', null, 'Iligan', 'Cagayan de Oro', 'Steel bars', 13500, 96000, 'cargo_rush', 'pending', 2],
    ];

    public function __construct(
        private readonly TripService $trips,
        private readonly WalletService $wallet,
    ) {}

    public function run(): void
    {
        $this->intoCompany(function (): void {
            $this->partners();
            $this->units();
            $this->partnerLogin();
            $this->runs();
            $this->settlements();

            $this->report();
        });
    }

    private function partners(): void
    {
        foreach (self::PARTNERS as $row) {
            [$name, $phone, $licence, $expiry, $status, $online, $lat, $lng, $done] = $row;

            $this->restoreOrCreate(Trucker::class, ['phone' => $phone], [
                'name' => $name,
                'licence_no' => $licence,
                'licence_expiry' => $expiry,
                'status' => $status,
                'is_online' => $online,
                'latitude' => $lat,
                'longitude' => $lng,
                // A pin is only worth sorting a job board by if it is recent —
                // `hasFreshPosition()` wants one inside two hours — so it is
                // stamped relative to now rather than to a fixed day.
                'located_at' => $lat === null ? null : now()->subMinutes(random_int(4, 40)),
                'trips_completed' => $done,
            ]);
        }
    }

    private function units(): void
    {
        $partners = Trucker::query()->pluck('id', 'phone');
        $categories = TruckCategory::query()->pluck('id', 'key');

        foreach (self::UNITS as [$phone, $plate, $model, $capacity, $category, $status]) {
            $this->restoreOrCreate(TruckerVehicle::class, ['plate' => $plate], [
                'trucker_id' => $partners[$phone],
                'model' => $model,
                'capacity_kg' => $capacity,
                // Null where the catalogue has not been seeded. The category is
                // how a freezer load is matched to a freezer, and a missing one
                // reads as "not stated" rather than as a refusal.
                'truck_category_id' => $categories[$category] ?? null,
                'status' => $status,
            ]);
        }
    }

    /**
     * A login for one partner, so the third face of `cargoApp` opens on
     * something.
     *
     * Demo data and only here, exactly as the customer account in `FleetSeeder`
     * is: a real partner login is made by the person registering on their own
     * handset, or with `php artisan cargo:user --role=trucker`.
     */
    private function partnerLogin(): void
    {
        $partner = Trucker::query()->firstWhere('phone', '0917 555 0301');

        if ($partner === null) {
            return;
        }

        $user = User::updateOrCreate(['email' => 'dennis@cargorush.ph'], [
            'name' => $partner->name,
            'phone' => $partner->phone,
            'password' => Hash::make((string) env('SEED_PASSWORD', 'password')),
            'role' => Role::Trucker->value,
        ]);

        $partner->update(['user_id' => $user->id]);
    }

    /**
     * The work, and closing out whatever has been delivered.
     *
     * Closed through `TripService::complete()` rather than by writing a
     * `delivered` status and a wallet row by hand. That service is what the
     * handset calls, and putting the demo through it means the proof of
     * delivery, the wallet entry, the commission frozen onto the trip and the
     * customer's invoice are all produced by the code under test rather than
     * imitated by a fixture that will drift from it.
     */
    private function runs(): void
    {
        $partners = Trucker::query()->get()->keyBy('phone');
        $units = TruckerVehicle::query()->get()->groupBy('trucker_id');
        $customers = Customer::query()->pluck('id', 'name')->values();

        if ($customers->isEmpty()) {
            $this->command?->warn('No customers to book partner work for — run FleetSeeder first.');

            return;
        }

        foreach (self::RUNS as $i => $row) {
            [$ref, $phone, $from, $to, $cargo, $kg, $peso, $source, $status, $days] = $row;

            /**
             * A run that has already been closed out is left exactly as it is.
             *
             * Not tidiness — correctness. `updateOrCreate` below would put a
             * delivered run back to `assigned`, the pass beneath would close it
             * a second time, and the unique index over (`trip_id`, `kind`) in
             * the wallet would refuse the second entry with the whole seeder
             * halfway through. `billed_at` is the same guard a real delivery
             * uses for the same reason.
             */
            if (Trip::query()->firstWhere('reference', $ref)?->isBilled()) {
                continue;
            }

            $partner = $phone === null ? null : $partners[$phone] ?? null;
            $unit = $partner === null ? null : ($units[$partner->id] ?? collect())
                ->firstWhere('status', StatusValue::Available);

            $scheduled = Carbon::now()->addDays($days)->setTime(7, 30);

            $trip = $this->restoreOrCreate(Trip::class, ['reference' => $ref], [
                'customer_id' => $customers[$i % $customers->count()],
                'origin' => $from,
                'destination' => $to,
                'cargo' => $cargo,
                'weight_kg' => $kg,
                'pieces' => (int) ceil($kg / 300),
                'handling' => 'Keep dry · stack max 3 high',
                'trucker_id' => $partner?->id,
                'trucker_vehicle_id' => $unit?->id,
                'booking_source' => $source,
                // A partner's run has no fleet unit on it, and that is what
                // keeps it off the daily truck sheet: the sheet is one company
                // truck's diesel, crew and maintenance, and a partner's truck
                // has none of those in this system.
                'vehicle_id' => null,
                'driver_id' => null,
                // Delivered runs are created as `assigned` and then closed
                // through the service below, which is what stamps the status.
                'status' => $status === 'delivered' ? StatusValue::Assigned->value : $status,
                'pickup_place' => "{$from} yard",
                'dropoff_place' => "Consignee, {$to}",
                'scheduled_at' => $scheduled,
                'eta' => $status === 'pending' ? null : $scheduled->copy()->addHours(6),
                'distance_total_m' => random_int(70, 190) * 1000,
                'price_cents' => $peso * 100,
                'currency' => 'PHP',
            ]);

            if ($status === 'delivered' && $trip->status !== StatusValue::Delivered) {
                // Back-dated: the wallet row, the invoice and the statement all
                // take their date from here, and a week of runs all landing
                // today would make the statement unreadable.
                Carbon::setTestNow($scheduled->copy()->addHours(6));

                $this->trips->complete($trip, new ProofData(receiver_name: 'R. Ledesma'));

                Carbon::setTestNow();
            }
        }
    }

    /**
     * Money actually changing hands, in all three states the wallet has.
     *
     * A wallet whose every row is an unsettled earning shows one of the three
     * badges the statement can print. The office needs to see the other two —
     * a transfer still in the air, and one that has landed — because "paid" and
     * "sent" being different facts on different days is the whole reason the
     * settlement has a status of its own.
     */
    private function settlements(): void
    {
        $this->payOut('0917 555 0301', 'PAYOUT-DEMO-0001', cleared: true);
        $this->payOut('0917 555 0302', 'PAYOUT-DEMO-0002', cleared: false);

        $this->adjust(
            '0917 555 0303',
            -150000,
            'Tarpaulin and straps issued from the yard, 10 Sep.',
        );
    }

    /**
     * Pay a partner for everything outstanding, once.
     *
     * The reference is the guard. `payOut()` refuses a partner who is owed
     * nothing, so a re-run would abort the whole seeder on its second pass —
     * finding the row this wrote last time is what makes running it again a
     * no-op rather than a 422.
     */
    private function payOut(string $phone, string $reference, bool $cleared): void
    {
        $partner = Trucker::query()->firstWhere('phone', $phone);

        if ($partner === null || WalletEntry::query()->where('reference', $reference)->exists()) {
            return;
        }

        $owed = WalletEntry::query()
            ->where('trucker_id', $partner->getKey())
            ->where('kind', WalletEntryKind::Earning->value)
            ->whereNull('settled_by')
            ->exists();

        if (! $owed) {
            return;
        }

        $this->wallet->payOut(
            trucker: $partner,
            reference: $reference,
            note: $cleared ? 'Cash across the desk.' : 'Bank transfer, in flight.',
            occurredOn: now()->subDays($cleared ? 3 : 1),
            cleared: $cleared,
        );
    }

    /** A correction with a reason on it, which is the only kind there is. */
    private function adjust(string $phone, int $cents, string $note): void
    {
        $partner = Trucker::query()->firstWhere('phone', $phone);

        if ($partner === null) {
            return;
        }

        $already = WalletEntry::query()
            ->where('trucker_id', $partner->getKey())
            ->where('kind', WalletEntryKind::Adjustment->value)
            ->where('note', $note)
            ->exists();

        if ($already) {
            return;
        }

        $this->wallet->adjust($partner, $cents, $note, occurredOn: now()->subDays(13));
    }

    private function report(): void
    {
        $partners = Trucker::query()->count();
        $taking = Trucker::query()->takingWork()->count();
        $owed = (int) WalletEntry::query()->landed()->sum('amount_cents');

        $this->command?->info(sprintf(
            '  %d partners (%d able to take work), %s outstanding across their wallets.',
            $partners,
            $taking,
            number_format($owed / 100, 2),
        ));
    }
}
