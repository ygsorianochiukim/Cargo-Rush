<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Hr\Models\Applicant;
use App\Domain\Inspection\Models\Inspection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VehicleArrangement;
use App\Domain\Shared\Enums\WalletEntryKind;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Vehicle\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoSeeder;
use Illuminate\Support\Carbon;

/**
 * The walkthrough data, checked against what it promises.
 *
 * A seeder is the one piece of code whose entire job is to be looked at, so a
 * test that only counts rows proves the wrong thing. What matters is that the
 * figures on screen are arithmetic somebody can follow — a partner owed
 * ₱105,600 on a ₱120,000 run at twelve per cent — and that the states each
 * screen exists to tell apart are all actually present.
 *
 * The second thing it proves is that running it twice is safe. The natural
 * thing to do after a confusing demo is to seed again, and a wallet that paid
 * somebody a second time for the same run would be the worst possible way to
 * find out it was not idempotent.
 */
beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoSeeder::class);
});

describe('the sub-contracted partners', function (): void {
    it('seeds every state the roster has to tell apart', function (): void {
        $partners = Trucker::query()->get()->keyBy('name');

        expect($partners)->toHaveCount(8);

        // Vetted and switched on — the only combination that may be given work.
        expect($partners['Dennis Abella']->canTakeWork())->toBeTrue();

        // Vetted, switch off. Trusted and not working today, which is a
        // different fact from not being trusted.
        expect($partners['Ronel Pacaldo']->isVetted())->toBeTrue()
            ->and($partners['Ronel Pacaldo']->canTakeWork())->toBeFalse();

        // A registration nobody has read yet: the row the sidebar badge counts.
        expect($partners['Joel Mendez']->status)->toBe(StatusValue::Pending)
            ->and($partners['Joel Mendez']->canTakeWork())->toBeFalse();

        expect($partners['Fely Dagondon']->status)->toBe(StatusValue::Inactive);

        // Two trucks, one in the shop, so "the first that is not" has something
        // to choose between.
        expect($partners['Dennis Abella']->vehicles)->toHaveCount(2)
            ->and($partners['Dennis Abella']->activeVehicle()?->plate)->toBe('CDO 8821');
    });

    it('leaves a job board a partner can open the app onto', function (): void {
        $open = Trip::query()
            ->where('status', StatusValue::Pending->value)
            ->whereNull('trucker_id')
            ->count();

        expect($open)->toBeGreaterThanOrEqual(2);
    });

    it('credits a brokered run and charges a direct one', function (): void {
        $dennis = Trucker::query()->where('phone', '0917 555 0301')->firstOrFail();

        $entries = WalletEntry::query()
            ->where('trucker_id', $dennis->getKey())
            ->with('trip')
            ->get()
            ->keyBy(fn (WalletEntry $entry): string => $entry->trip?->reference ?? $entry->kind->value);

        /**
         * ₱120,000 the haulier invoiced and will collect, at twelve per cent.
         * It keeps ₱14,400 and owes the partner ₱105,600 — positive, because
         * the money is in the haulier's hands.
         */
        expect($entries['CR-24901']->kind)->toBe(WalletEntryKind::Earning)
            ->and($entries['CR-24901']->amount_cents)->toBe(10_560_000)
            ->and($entries['CR-24901']->gross_cents)->toBe(12_000_000)
            ->and($entries['CR-24901']->rate_bp)->toBe(1200);

        /**
         * ₱45,000 the partner billed and collected themselves. Only the
         * haulier's cut is owed, and it runs the other way — negative.
         */
        expect($entries['CR-24902']->kind)->toBe(WalletEntryKind::Commission)
            ->and($entries['CR-24902']->amount_cents)->toBe(-540_000);

        // The rate is frozen onto the run as well, which is what the office
        // reads when a partner queries a figure months later.
        expect(Trip::query()->where('reference', 'CR-24901')->value('commission_bp'))->toBe(1200);
    });

    it('shows a payment that landed and one still in the air', function (): void {
        $cleared = WalletEntry::query()->where('reference', 'PAYOUT-DEMO-0001')->firstOrFail();
        $inFlight = WalletEntry::query()->where('reference', 'PAYOUT-DEMO-0002')->firstOrFail();

        expect($cleared->status)->toBe(StatusValue::Paid)
            ->and($inFlight->isInFlight())->toBeTrue();

        // The runs each one covers are settled by it — a payout is a set of
        // runs rather than an amount, and this is the column that answers
        // "have you paid me for CR-24901?".
        $settled = WalletEntry::query()->where('settled_by', $cleared->getKey())->get();

        expect($settled)->not->toBeEmpty()
            ->and($settled->first()->paymentState())->toBe('paid');

        $processing = WalletEntry::query()->where('settled_by', $inFlight->getKey())->first();

        expect($processing?->paymentState())->toBe('processing');
    });
});

describe('the hired trucks', function (): void {
    it('puts one unit on the fleet for each way of paying for one', function (): void {
        $arrangements = Vehicle::query()
            ->whereIn('plate', ['RNT 1001', 'SHR 2001', 'SUB 3001', 'NCR 4412'])
            ->pluck('arrangement', 'plate');

        expect($arrangements['NCR 4412'])->toBe(VehicleArrangement::Owned)
            ->and($arrangements['RNT 1001'])->toBe(VehicleArrangement::Rented)
            ->and($arrangements['SHR 2001'])->toBe(VehicleArrangement::RentedShare)
            ->and($arrangements['SUB 3001'])->toBe(VehicleArrangement::SubContracted);
    });

    it('owes the owner a share of a run and says so on the truck sheet', function (): void {
        $trip = Trip::query()->where('reference', 'CR-24911')->firstOrFail();

        // ₱100,000 at fifteen per cent: ₱15,000 to the fleet, ₱85,000 to the
        // owner of the ten-wheeler.
        $share = WalletEntry::query()->where('trip_id', $trip->getKey())->firstOrFail();

        expect($share->amount_cents)->toBe(8_500_000)
            ->and($share->rate_bp)->toBe(1500)
            ->and($share->trucker->name)->toBe('Teresita Bacus');

        /**
         * The same figure on the day's sheet, not a second calculation. Without
         * it a share truck shows its full income against almost no costs and
         * reads as the best performer on the fleet.
         */
        $sheet = LedgerEntry::query()->where('trip_id', $trip->getKey())->firstOrFail();

        expect($sheet->trip_income_cents)->toBe(10_000_000)
            ->and($sheet->owner_share_cents)->toBe(8_500_000);
    });

    it('leaves a flat-rented truck keeping every peso, and bills its rent monthly', function (): void {
        $trip = Trip::query()->where('reference', 'CR-24915')->firstOrFail();

        // Nobody outside the fleet is owed a share of a run on a truck that was
        // already paid for by the month.
        expect(WalletEntry::query()->where('trip_id', $trip->getKey())->exists())->toBeFalse();

        $rent = Expense::query()->where('reference', 'like', 'RENT RNT 1001%')->get();

        expect($rent)->toHaveCount(2)
            ->and($rent->first()->amount_cents)->toBe(5_000_000);
    });

    it('shows a share unit nobody has been named on as owing nothing', function (): void {
        $halfConfigured = Vehicle::query()->where('plate', 'SHR 2003')->firstOrFail();

        expect($halfConfigured->terms())->toBe(VehicleArrangement::RentedShare)
            ->and($halfConfigured->sharesRevenue())->toBeFalse();
    });
});

describe('the rest of the walkthrough', function (): void {
    it('leaves no module opening on an empty table', function (): void {
        $tables = [
            'companies', 'users', 'drivers', 'vehicles', 'customers', 'trips',
            'truckers', 'trucker_vehicles', 'trucker_wallet_entries',
            'suppliers', 'expenses', 'inspections', 'incidents', 'fuel_records',
            'maintenance_jobs', 'gps_pings', 'delivery_logs', 'dispatch_records',
            'employees', 'contracts', 'applicants', 'leave_requests',
            'undertime_requests', 'store_credits', 'pay_components',
            'employee_pay_components', 'payroll_cutoff_requests',
            'invoices', 'ledger_entries', 'trucks', 'accounts',
            'diesel_prices', 'pricing_zones', 'pricing_brackets',
            'truck_categories', 'positions', 'roles', 'notification_items',
        ];

        $empty = collect($tables)->filter(static fn (string $table): bool => DB::table($table)->count() === 0);

        expect($empty->all())->toBe([]);
    });

    it('holds a unit whose pre-trip check failed', function (): void {
        $failed = Inspection::query()->where('good_to_go', false)->get();

        expect($failed)->not->toBeEmpty()
            ->and($failed->first()->failures())->toContain('brakes');
    });

    it('has every applicant stage and a request still waiting on a decision', function (): void {
        $stages = Applicant::query()->get()
            ->map(static fn (Applicant $applicant): string => $applicant->stage->value)
            ->unique()->sort()->values()->all();

        expect($stages)->toBe(['applied', 'hired', 'interview', 'offered', 'rejected', 'screening']);

        expect(DB::table('leave_requests')->where('status', 'pending')->count())->toBeGreaterThan(0)
            ->and(DB::table('undertime_requests')->where('status', 'pending')->count())->toBeGreaterThan(0)
            ->and(DB::table('payroll_cutoff_requests')->where('status', 'pending')->count())->toBe(1);
    });

    it('charges a service to the unit it was done on, once', function (): void {
        $vehicle = Vehicle::query()->where('plate', 'MAR1390')->firstOrFail();
        $job = $vehicle->maintenanceJobs()->where('kind', 'Gearbox overhaul')->firstOrFail();

        expect($job->cost_cents)->toBe(4_850_000)
            ->and($job->posted_cents)->toBe(4_850_000)
            ->and($job->supplier?->name)->toBe('Iponan Machine Shop');

        $onTheSheet = (int) LedgerEntry::query()
            ->whereDate('date', $job->completed_on)
            ->whereHas('truck', fn ($query) => $query->where('vehicle_id', $vehicle->getKey()))
            ->sum('maintenance_cents');

        expect($onTheSheet)->toBe(4_850_000);
    });
});

it('can be run twice without paying anybody twice', function (): void {
    $before = [
        'trips' => DB::table('trips')->count(),
        'wallet' => DB::table('trucker_wallet_entries')->count(),
        'owed' => (int) DB::table('trucker_wallet_entries')->sum('amount_cents'),
        'expenses' => DB::table('expenses')->count(),
        'ledger' => (int) DB::table('ledger_entries')->sum('trip_income_cents'),
        'truckers' => DB::table('truckers')->count(),
        'vehicles' => DB::table('vehicles')->count(),
        'contracts' => DB::table('contracts')->count(),
        'maintenance' => (int) DB::table('ledger_entries')->sum('maintenance_cents'),
    ];

    // A day later, because a second run is never the same afternoon and dates
    // derived from `today` are exactly where a seeder tends to double up.
    Carbon::setTestNow(now()->addDay());

    $this->seed(DemoSeeder::class);

    Carbon::setTestNow();

    expect(DB::table('trips')->count())->toBe($before['trips'])
        ->and(DB::table('trucker_wallet_entries')->count())->toBe($before['wallet'])
        ->and((int) DB::table('trucker_wallet_entries')->sum('amount_cents'))->toBe($before['owed'])
        ->and(DB::table('expenses')->count())->toBe($before['expenses'])
        ->and((int) DB::table('ledger_entries')->sum('trip_income_cents'))->toBe($before['ledger'])
        ->and((int) DB::table('ledger_entries')->sum('maintenance_cents'))->toBe($before['maintenance'])
        ->and(DB::table('truckers')->count())->toBe($before['truckers'])
        ->and(DB::table('vehicles')->count())->toBe($before['vehicles'])
        ->and(DB::table('contracts')->count())->toBe($before['contracts']);
});
