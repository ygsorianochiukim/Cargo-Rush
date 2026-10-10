<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\DTO\ProofData;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Shared\Enums\Role;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\TruckVerification;
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

class PartnerSeeder extends Seeder
{
    use AdoptsTrashedRows, SeedsIntoACompany;

    private const PARTNERS = [
        ['Dennis Abella', '0917 555 0301', 'CDO-21-448190', '2028-05-14', 'active', true, 8.4772, 124.6450, 138],
        ['Marivic Ong', '0917 555 0302', 'BUK-19-220741', '2027-11-08', 'active', true, 8.1575, 125.1278, 92],
        ['Ronel Pacaldo', '0917 555 0303', 'MIS-20-771203', '2027-03-22', 'active', false, 8.2280, 124.2452, 64],
        ['Joel Mendez', '0917 555 0304', 'LAN-22-903318', '2029-01-30', 'pending', false, 8.4304, 124.6319, 0],
        ['Fely Dagondon', '0917 555 0305', 'CDO-18-110925', '2026-12-04', 'inactive', false, null, null, 41],
        ['Arnel Quibod', '0917 555 0306', 'CDO-21-556037', '2028-09-19', 'active', true, 8.4856, 124.5808, 57],
    ];

    private const UNITS = [
        ['0917 555 0301', 'CDO 8821', 'Isuzu Forward', 15000, 'dry-goods', 'available'],
        ['0917 555 0301', 'CDO 4417', 'Fuso Fighter', 8000, 'dry-goods', 'maintenance'],
        ['0917 555 0302', 'BUK 3390', 'Hino 500', 12000, 'freezer', 'available'],
        ['0917 555 0303', 'MIS 7028', 'UD Quester', 16000, 'flatbed', 'available'],
        ['0917 555 0304', 'LAN 1174', 'Isuzu Giga', 14000, 'dry-goods', 'available'],
    ];

    private const RUNS = [
        ['CR-24901', '0917 555 0301', 'Cagayan de Oro', 'Iligan', 'Bagged cement, 200 sacks', 12000, 120000, 'cargo_rush', 'delivered', -9],
        ['CR-24902', '0917 555 0301', 'Cagayan de Oro', 'Gingoog', 'Assorted hardware', 6400, 45000, 'direct', 'delivered', -6],
        ['CR-24903', '0917 555 0302', 'Valencia', 'Cagayan de Oro', 'Chilled dressed chicken', 9000, 86000, 'cargo_rush', 'delivered', -4],
        ['CR-24904', '0917 555 0302', 'Malaybalay', 'Cagayan de Oro', 'Sacked corn', 11000, 64000, 'cargo_rush', 'delivered', -2],
        ['CR-24905', '0917 555 0301', 'Cagayan de Oro', 'Ozamiz', 'Retail cartons', 5200, 52000, 'cargo_rush', 'in_transit', 0],
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
                'truck_category_id' => $categories[$category] ?? null,
                'status' => $status,
                'verification' => TruckVerification::Verified->value,
                'verified_at' => now(),
            ]);
        }
    }

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
                'vehicle_id' => null,
                'driver_id' => null,
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
                $clock = Carbon::getTestNow();
                Carbon::setTestNow($scheduled->copy()->addHours(6));

                $this->trips->complete($trip, new ProofData(receiver_name: 'R. Ledesma'));

                Carbon::setTestNow($clock);
            }
        }
    }

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
