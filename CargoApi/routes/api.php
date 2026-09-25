<?php

declare(strict_types=1);

use App\Domain\Accounting\Controllers\AccountController;
use App\Domain\Accounting\Controllers\GeneralLedgerController;
use App\Domain\Accounting\Controllers\JournalController;
use App\Domain\Accounting\Controllers\StatementController;
use App\Domain\Billing\Controllers\BillingController;
use App\Domain\Billing\Controllers\PaymentController;
use App\Domain\Customer\Controllers\CustomerController;
use App\Domain\Customer\Controllers\PortalController;
use App\Domain\Customer\Controllers\ShipperRegistrationController;
use App\Domain\Dashboard\Controllers\DashboardController;
use App\Domain\Delivery\Controllers\DeliveryController;
use App\Domain\Dispatch\Controllers\DispatchController;
use App\Domain\Driver\Controllers\DriverController;
use App\Domain\Finance\Controllers\ExpenseController;
use App\Domain\Finance\Controllers\FinanceController;
use App\Domain\Finance\Controllers\PayablesController;
use App\Domain\Fuel\Controllers\FuelController;
use App\Domain\Gps\Controllers\GpsController;
use App\Domain\Hr\Controllers\ApplicantController;
use App\Domain\Hr\Controllers\ContractController;
use App\Domain\Hr\Controllers\EmployeeController;
use App\Domain\Hr\Controllers\PerformanceController;
use App\Domain\Hr\Controllers\TimeOffController;
use App\Domain\Identity\Controllers\AccessController;
use App\Domain\Identity\Controllers\AuthController;
use App\Domain\Identity\Controllers\MeController;
use App\Domain\Identity\Controllers\NavigationController;
use App\Domain\Identity\Controllers\PasswordController;
use App\Domain\Incident\Controllers\DriverIncidentController;
use App\Domain\Incident\Controllers\IncidentController;
use App\Domain\Inspection\Controllers\InspectionController;
use App\Domain\Notification\Controllers\NotificationController;
use App\Domain\Payroll\Controllers\PayComponentController;
use App\Domain\Payroll\Controllers\PayrollController;
use App\Domain\Payroll\Controllers\PayrollCutoffRequestController;
use App\Domain\Payroll\Controllers\StoreCreditController;
use App\Domain\Pricing\Controllers\PricingController;
use App\Domain\Supplier\Controllers\SupplierController;
use App\Domain\Tenancy\Controllers\CarrierController;
use App\Domain\Tenancy\Controllers\CompanyController;
use App\Domain\Tenancy\Controllers\RegistrationController;
use App\Domain\Trip\Controllers\DriverTripController;
use App\Domain\Trip\Controllers\TripController;
use App\Domain\Trucker\Controllers\PartnerController;
use App\Domain\Trucker\Controllers\TruckerController;
use App\Domain\Trucker\Controllers\TruckerRegistrationController;
use App\Domain\Vehicle\Controllers\MaintenanceController;
use App\Domain\Vehicle\Controllers\VehicleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CargoApi v1
|--------------------------------------------------------------------------
|
| Versioned and grouped, per DESIGN.md section 7.4. Public routes sit outside
| the `auth:sanctum` group; everything else is inside it.
|
| The route list is organised by the module map in section 5.1, so this file
| reads as the same information architecture the sidebar does.
*/

Route::prefix('v1')->group(function (): void {

    /* ------------------------------------------------------------- Public */

    // Registering a company is the only public write there is. It creates the
    // firm and the first account together and answers signed in, in the same
    // shape login does — so a client carries on into the app through one code
    // path rather than two.
    //
    // Throttled per IP per hour: a successful call here costs the platform a
    // company, a set of roles and a permanent row, so it is the one endpoint
    // where the *successes* are worth metering as well as the failures.
    Route::post('register', RegistrationController::class)->middleware('throttle:register');

    /**
     * A shipper signing themselves up.
     *
     * The second way a customer account comes to exist, and the two mean
     * different things. An office adding a firm in Customer Management creates
     * a login that belongs to that haulier — it books with them, reads their
     * invoices, and is shown no other carrier. This creates a login that
     * arrived at the *platform* and belongs to nobody: it picks a haulier per
     * load, on the request form, and may pick a different one next week.
     *
     * A name, a number and a login is all it takes. No carrier and no address,
     * because both are answered per load by whoever is standing next to it —
     * see `RegisterShipperRequest`.
     *
     * On the same limiter as a company registration: it writes a login on an
     * unauthenticated call, so the successes are worth metering too.
     */
    Route::post('register/customer', ShipperRegistrationController::class)
        ->middleware('throttle:register');

    /**
     * An owner-operator signing themselves up.
     *
     * The third public write, and the one that asks for the most: a name, a
     * number, a login, a licence, a truck — and the fleet they want to haul
     * for. The last is the difference from the shipper's form above, and it is
     * not an inconsistency. A shipper picks a carrier per load because that is
     * a choice made weekly with the load in front of them; a partner's
     * relationship is a standing one with a rate, a vetting and a running
     * balance, and none of those can exist with nobody. See
     * `TruckerRegistrationService`.
     *
     * What it does not do is let them start working. The account lands
     * `pending` and the job board stays empty until somebody at that fleet has
     * read the licence and said yes.
     *
     * Same limiter as the other two registrations: it writes a login on an
     * unauthenticated call, so the successes are metered as well as the
     * failures.
     */
    Route::post('register/trucker', TruckerRegistrationController::class)
        ->middleware('throttle:register');

    /**
     * Who is hauling near here — the only public read there is.
     *
     * Not part of signing up any more, and still public for the reason it
     * always was: a directory behind a login would mean asking a firm to
     * register with a platform before showing them whether anybody on it serves
     * their town. What it exposes is what a haulier paints on the side of a
     * truck — see `CarrierController`. A shipper who has an account reads
     * `portal/carriers` instead, which is this list plus which of them they
     * already deal with.
     */
    Route::get('carriers', CarrierController::class)->middleware('throttle:carriers');

    // Throttled on the address being tried *and* the caller's IP — see the
    // `login` limiter for why neither works on its own.
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login');

    /**
     * Getting back in.
     *
     * Public, necessarily: somebody who cannot sign in cannot authenticate to
     * ask for help. Both are metered on the `login` limiter — the same budget
     * as a sign-in attempt, because they are the same thing being abused. An
     * unmetered `forgot-password` is a way to send somebody a hundred emails.
     *
     * Neither knows or needs a company. `password_reset_tokens` is keyed by
     * address, and an address belongs to exactly one account system-wide, so
     * the token identifies the account and the account names the company.
     */
    Route::post('forgot-password', [PasswordController::class, 'forgot'])
        ->middleware('throttle:login');

    Route::post('reset-password', [PasswordController::class, 'reset'])
        ->middleware('throttle:login');

    /* -------------------------------------------- Signed in, no company */

    // The only authenticated endpoint outside the company group below. Signing
    // out touches no company data, and somebody whose firm has just been
    // suspended must still be able to get out of the app — requiring an active
    // company in order to leave one would strand them on a screen they can
    // neither use nor close.
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

    // Changing your own password. Outside the company group for the same
    // reason logout is: it touches the account, not the company's data, and
    // somebody whose firm is suspended should still be able to secure their
    // own login.
    Route::post('me/password', [PasswordController::class, 'change'])->middleware('auth:sanctum');

    /* ---------------------------------------------------- Authenticated */

    /**
     * Three, in this order, and the order is the security property.
     *
     * `auth:sanctum` establishes *who*. `tenant` establishes *whose data* — the
     * caller's company, off the account and from nowhere else, in force before
     * any controller here runs so every query underneath is filtered to it.
     *
     * `bindings` is last because it *is* a query. Route model binding turns
     * `{vehicle}` into a row, and a row fetched before the company is in force
     * is fetched from every company on the platform — `VehicleController::show()`
     * would then return whatever the id named. It is a route middleware rather
     * than the group middleware Laravel ships with precisely so it can be put
     * after the other two; see `bootstrap/app.php`.
     */
    Route::middleware(['auth:sanctum', 'tenant', 'bindings'])->group(function (): void {

        // The two endpoints that make the shell data-driven (section 7.2, 7.3).
        Route::get('me', MeController::class);
        Route::get('navigation', NavigationController::class);

        /* ------------------------------------------------------ Operations */

        Route::prefix('dashboard')->group(function (): void {
            Route::get('kpis', [DashboardController::class, 'kpis']);
            Route::get('fleet', [DashboardController::class, 'fleet']);
            Route::get('deliveries', [DashboardController::class, 'deliveries']);
            Route::get('activity', [DashboardController::class, 'activity']);
            Route::get('receivables', [DashboardController::class, 'receivables']);
        });

        // The driver's own work. Declared before the `trips/{trip}` resource so
        // `trips/current` is never read as an id.
        //
        // Gated on `trips.view`, not `trips.manage`: a driver reads their own
        // queue and reports what happened on the road, which is a different
        // thing from booking work.
        Route::prefix('trips')->middleware('permission:trips.view')->group(function (): void {
            Route::get('current', [DriverTripController::class, 'current']);
            Route::get('pending', [DriverTripController::class, 'pending']);
            Route::get('upcoming', [DriverTripController::class, 'upcoming']);
            Route::get('cargo', [DriverTripController::class, 'cargo']);

            // The hand-off. Scoped to the caller like the reads above, so it
            // carries no trip id to change.
            Route::post('current/deliver', [DriverTripController::class, 'deliver'])
                ->middleware('permission:delivery.write');

            // Starting names a trip, because several may be waiting. The
            // service checks it is the caller's before acting on it.
            Route::post('{trip}/start', [DriverTripController::class, 'start']);
        });

        Route::get('trips', [TripController::class, 'index'])->middleware('permission:trips.view');
        Route::get('trips/{trip}', [TripController::class, 'show'])->middleware('permission:trips.view');

        // Everything that changes the board needs `trips.manage`. Confirming is
        // the desk's one action on a customer's request: it names the crew, the
        // unit and the time, and `assigned` follows from them — so it is a verb,
        // not a status PATCH.
        Route::middleware('permission:trips.manage')->group(function (): void {
            Route::post('trips', [TripController::class, 'store']);
            Route::match(['put', 'patch'], 'trips/{trip}', [TripController::class, 'update']);
            Route::delete('trips/{trip}', [TripController::class, 'destroy']);
            Route::post('trips/{trip}/confirm', [TripController::class, 'confirm']);
            Route::post('trips/{trip}/dispatch', [TripController::class, 'dispatchTrip']);
            Route::post('trips/{trip}/complete', [TripController::class, 'complete']);
        });

        // GPS: the web reads `index`, the handset writes `store`.
        Route::get('gps', [GpsController::class, 'index'])->middleware('permission:gps.view');
        Route::post('gps/pings', [GpsController::class, 'store'])->middleware('permission:gps.write');
        Route::get('gps/trips/{trip}/tracking', [GpsController::class, 'tracking'])
            ->middleware('permission:gps.view,gps.write');

        Route::middleware('permission:dispatch.view')->group(function (): void {
            Route::get('dispatch', [DispatchController::class, 'index']);
            Route::get('dispatch/{dispatch}', [DispatchController::class, 'show']);
            Route::post('dispatch/{dispatch}/arrive', [DispatchController::class, 'arrive']);
        });

        Route::middleware('permission:delivery.view')->group(function (): void {
            Route::get('delivery-logs/report', [DeliveryController::class, 'report']);
            Route::get('delivery-logs', [DeliveryController::class, 'index']);
            Route::get('delivery-logs/{delivery}', [DeliveryController::class, 'show']);
        });

        Route::post('delivery-logs/{delivery}/proof', [DeliveryController::class, 'proof'])
            ->middleware('permission:delivery.write');

        /* ---------------------------------------------------------- Assets */

        /**
         * Reading the fleet is wider than managing it, for the same reason
         * reading the supplier list is.
         *
         * Truck Maintenance asks which truck a garage's bill was for, and the
         * person answering is whoever files the fleet's spend — an accountant,
         * who holds `expenses.manage` and no fleet permission at all. Gated on
         * `vehicles.view` alone, that screen's first field is an empty picker
         * and the bill cannot be filed by the only person holding it.
         *
         * `finance.view` for the same reason on the reporting side: Payables
         * and Profitability both name units.
         *
         * Writing a unit stays where it was — that is the yard's record.
         */
        Route::middleware('permission:vehicles.view,expenses.view,finance.view')->group(function (): void {
            Route::get('vehicles', [VehicleController::class, 'index']);
            Route::get('vehicles/{vehicle}', [VehicleController::class, 'show']);
            Route::get('vehicles/{vehicle}/maintenance', [VehicleController::class, 'maintenance']);
        });

        Route::middleware('permission:vehicles.manage')->group(function (): void {
            Route::post('vehicles', [VehicleController::class, 'store']);
            Route::match(['put', 'patch'], 'vehicles/{vehicle}', [VehicleController::class, 'update']);
            Route::delete('vehicles/{vehicle}', [VehicleController::class, 'destroy']);
            Route::post('vehicles/{vehicle}/status', [VehicleController::class, 'status']);

            /**
             * Servicing, and what it came to.
             *
             * Under `vehicles.manage` rather than `expenses.manage`, although
             * a costed job moves money: this is the yard's record of what was
             * done to a unit, and whoever keeps that is who knows the figure
             * the garage handed back. The alternative was making somebody hold
             * the expenses permission to write down an oil change.
             *
             * Nested under the unit so a job id from another vehicle cannot be
             * edited through it — the controller resolves it off the relation.
             */
            Route::post('vehicles/{vehicle}/maintenance', [VehicleController::class, 'storeMaintenance']);
            Route::match(['put', 'patch'], 'vehicles/{vehicle}/maintenance/{jobId}', [VehicleController::class, 'updateMaintenance']);
            Route::delete('vehicles/{vehicle}/maintenance/{jobId}', [VehicleController::class, 'destroyMaintenance']);
        });

        /**
         * Truck Maintenance — the same servicing, across the whole fleet.
         *
         * The nested routes above are the yard's view of one unit. These are
         * the office's view of all of them: what servicing has cost, on which
         * trucks, from which garages. One set of rows, two screens, and the
         * money still posts through `MaintenanceService` either way.
         *
         * ## Two permissions on the write, and that is deliberate
         *
         * `vehicles.manage` because it is the yard's record of what was done to
         * a unit, and `expenses.manage` because this screen sits in Sales &
         * Billing beside Other Expenses and the person filing a garage's
         * receipt is whoever files the fleet's spend. An accountant holds the
         * second and not the first, and making them ask a dispatcher to key an
         * invoice they are holding would be the sort of rule that ends with the
         * figure never being keyed at all.
         *
         * Reading is wider still: the fleet screen, the spend screen and a
         * supplier's history all show these rows.
         */
        Route::middleware('permission:vehicles.view,expenses.view,finance.view')->group(function (): void {
            Route::get('maintenance', [MaintenanceController::class, 'index']);
            Route::get('maintenance/{job}', [MaintenanceController::class, 'show']);
        });

        Route::middleware('permission:vehicles.manage,expenses.manage')->group(function (): void {
            Route::post('maintenance', [MaintenanceController::class, 'store']);
            Route::match(['put', 'patch'], 'maintenance/{job}', [MaintenanceController::class, 'update']);
            Route::delete('maintenance/{job}', [MaintenanceController::class, 'destroy']);
        });

        /**
         * Suppliers — who the fleet buys from.
         *
         * Read is wider than write on purpose. An expense form, a maintenance
         * job and a payable bill all pick from this list, and the three are
         * held by three different people; making every one of them hold
         * `suppliers.manage` to *choose* a garage would be handing out the
         * right to rename one.
         */
        Route::middleware('permission:suppliers.view,expenses.view,billing.view,vehicles.view')->group(function (): void {
            Route::get('suppliers', [SupplierController::class, 'index']);
            Route::get('suppliers/{supplier}', [SupplierController::class, 'show']);
            Route::get('suppliers/{supplier}/history', [SupplierController::class, 'history']);
        });

        Route::middleware('permission:suppliers.manage')->group(function (): void {
            Route::post('suppliers', [SupplierController::class, 'store']);
            Route::match(['put', 'patch'], 'suppliers/{supplier}', [SupplierController::class, 'update']);
            Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy']);
        });

        /**
         * The driver's own availability switch.
         *
         * Declared before `drivers/{driver}` so `me` is never read as an id,
         * the same way `trips/current` is declared before `trips/{trip}`.
         *
         * Outside both permission groups on purpose. `drivers.view` and
         * `drivers.manage` are the office's roster permissions and no driver
         * holds either, so the switch on the handset's dashboard answered 403
         * for the one person it belongs to. It needs no permission of its own:
         * the only row it can reach is the caller's, and an account with no
         * driver record gets a 404.
         */
        Route::post('drivers/me/availability', [DriverController::class, 'ownAvailability']);

        Route::middleware('permission:drivers.view')->group(function (): void {
            Route::get('drivers', [DriverController::class, 'index']);
            Route::get('drivers/{driver}', [DriverController::class, 'show']);
        });

        Route::middleware('permission:drivers.manage')->group(function (): void {
            Route::post('drivers', [DriverController::class, 'store']);
            Route::match(['put', 'patch'], 'drivers/{driver}', [DriverController::class, 'update']);
            Route::delete('drivers/{driver}', [DriverController::class, 'destroy']);
            Route::post('drivers/{driver}/availability', [DriverController::class, 'availability']);
        });

        /* ------------------------------------------- Partner truckers */

        /**
         * The partner's own screens — the third tab set in `cargoApp`.
         *
         * Declared before the office's `truckers/*` routes and under a prefix
         * of their own, because they are a different product rather than a
         * filtered view of the same one. Nothing under `partner` takes an id
         * that identifies the caller: every path resolves to the `truckers` row
         * behind the account and to nothing else, exactly as the driver's
         * `trips/*` resolve to a `drivers` row and the customer's `portal/*` to
         * a `customers` row.
         *
         * Gated on `partner.view`, which only the trucker role holds. Not on
         * `trips.view` — that is the whole board, and the whole point of these
         * endpoints is that a partner never sees it.
         */
        Route::prefix('partner')->middleware('permission:partner.view')->group(function (): void {
            Route::get('me', [PartnerController::class, 'profile']);

            // The switch and the pin. Under `partner.view` rather than
            // `partner.jobs`: a partner may always go offline, including one
            // the office has just stood down, and needing the job permission to
            // stop working would be exactly backwards.
            Route::post('availability', [PartnerController::class, 'availability']);
            Route::post('position', [PartnerController::class, 'position']);

            // Their money. Read-only from the handset — a partner watches the
            // balance and the office moves it.
            Route::get('wallet', [PartnerController::class, 'wallet']);

            // Their trucks. `vehicles` before `vehicles/{id}` for the usual
            // reason, and both theirs by construction.
            Route::get('vehicles', [PartnerController::class, 'vehicles']);
            Route::post('vehicles', [PartnerController::class, 'saveVehicle']);
            Route::match(['put', 'patch'], 'vehicles/{vehicleId}', [PartnerController::class, 'saveVehicle']);

            /**
             * The work itself, behind `partner.jobs`.
             *
             * Its own permission so a fleet can keep a partner on the roster
             * and off the board without suspending them — the standing on the
             * record is the loud version of that, and this is the quiet one.
             *
             * `current` and `history` are declared before `trips/{tripId}`
             * would be, and there is deliberately no such route: a partner
             * reads their own queue as a list, and a run they were never given
             * is not theirs to fetch by id.
             */
            Route::middleware('permission:partner.jobs')->group(function (): void {
                Route::get('jobs', [PartnerController::class, 'jobs']);
                Route::post('jobs/{tripId}/accept', [PartnerController::class, 'accept']);

                Route::get('trips/current', [PartnerController::class, 'current']);
                Route::get('trips/history', [PartnerController::class, 'history']);
                Route::get('trips', [PartnerController::class, 'trips']);

                Route::post('trips/{tripId}/start', [PartnerController::class, 'start']);
                // The hand-off, on the same permission a driver needs for it:
                // whoever is at the door signs the run off.
                Route::post('trips/{tripId}/deliver', [PartnerController::class, 'deliver'])
                    ->middleware('permission:delivery.write');
                // And the photograph that could not go up at the gate.
                Route::post('trips/{tripId}/proof', [PartnerController::class, 'proof'])
                    ->middleware('permission:delivery.write');
            });
        });

        /**
         * The office's roster of partners — CargoUI's Truckers module.
         *
         * `available` before `truckers/{trucker}`, so it is never read as an
         * id. The wallet is under `truckers.view` with the rest of the reading:
         * what a partner is owed is part of knowing who hauls for you, and an
         * office that could see the roster but not the balance would have to
         * ask the accountant what it already knows.
         */
        Route::middleware('permission:truckers.view')->group(function (): void {
            Route::get('truckers/available', [TruckerController::class, 'available']);
            Route::get('truckers', [TruckerController::class, 'index']);
            Route::get('truckers/{trucker}', [TruckerController::class, 'show']);
            Route::get('truckers/{trucker}/wallet', [TruckerController::class, 'wallet']);
            Route::get('truckers/{trucker}/vehicles', [TruckerController::class, 'vehicles']);
        });

        /**
         * Vetting, rates and money.
         *
         * All three under one permission, and all three consequential in
         * different ways: approving hands a stranger a customer's cargo,
         * a rate decides what every future run of theirs splits at, and a
         * payout moves money. None of them belongs to whoever merely answers
         * the phone.
         */
        Route::middleware('permission:truckers.manage')->group(function (): void {
            Route::post('truckers/{trucker}/approve', [TruckerController::class, 'approve']);
            Route::post('truckers/{trucker}/suspend', [TruckerController::class, 'suspend']);
            Route::post('truckers/{trucker}/wallet', [TruckerController::class, 'settle']);

            /**
             * Confirming a payment has landed.
             *
             * The second half of paying somebody, on `truckers.manage` like
             * the first. Deliberately **not** something the partner can do
             * from the handset: confirming that your own payment arrived is
             * not a thing the person being paid should be able to assert, and
             * the balance turns on it.
             */
            Route::post('truckers/{trucker}/wallet/{entryId}/confirm', [TruckerController::class, 'confirmPayment']);

            Route::post('truckers/{trucker}/vehicles', [TruckerController::class, 'saveVehicle']);
            Route::match(['put', 'patch'], 'truckers/{trucker}/vehicles/{vehicleId}', [TruckerController::class, 'saveVehicle']);

            /**
             * Handing a run to a partner, and taking it back.
             *
             * Under the trucker permission rather than `trips.manage`, and the
             * distinction is real: this is not booking work, it is deciding who
             * outside the company gets paid to haul it. A dispatcher who can
             * assign the fleet's own drivers is not thereby somebody who can
             * put a contractor on a customer's load.
             */
            Route::post('trips/{trip}/assign-trucker', [TruckerController::class, 'assign']);
            Route::delete('trips/{trip}/assign-trucker', [TruckerController::class, 'release']);
        });

        Route::get('fuel/budget', [FuelController::class, 'budget'])->middleware('permission:fuel.view');
        Route::get('fuel', [FuelController::class, 'index'])->middleware('permission:fuel.view');
        Route::get('fuel/{fuel}', [FuelController::class, 'show'])->middleware('permission:fuel.view');

        Route::middleware('permission:fuel.manage')->group(function (): void {
            Route::post('fuel', [FuelController::class, 'store']);
            Route::match(['put', 'patch'], 'fuel/{fuel}', [FuelController::class, 'update']);
            Route::delete('fuel/{fuel}', [FuelController::class, 'destroy']);
        });

        /* --------------------------------------------------------- Finance */

        Route::prefix('finance')->group(function (): void {
            Route::middleware('permission:finance.view')->group(function (): void {
                Route::get('trucks', [FinanceController::class, 'trucks']);
                Route::get('routes', [FinanceController::class, 'routes']);
                Route::get('profitability', [FinanceController::class, 'profitability']);
                Route::get('summary', [FinanceController::class, 'summary']);
                // What a period's Total expenses is made of, row by row.
                Route::get('expense-lines', [FinanceController::class, 'expenseLines']);
                // And what customers still owed at a date, invoice by invoice.
                Route::get('receivable-lines', [FinanceController::class, 'receivableLines']);

                /**
                 * Everything the fleet owes, across four modules.
                 *
                 * `finance.view` because it is a money overview, and
                 * read-only because it is a roll-up: each line names the
                 * screen that settles it, and settling still needs that
                 * module's own permission. Somebody can be trusted to see
                 * what the week costs without being able to pay any of it.
                 */
                Route::get('payables', PayablesController::class);
            });

            Route::middleware('permission:finance.manage')->group(function (): void {
                Route::post('trucks', [FinanceController::class, 'storeTruck']);
                Route::match(['put', 'patch'], 'trucks/{truck}', [FinanceController::class, 'updateTruck']);
                Route::delete('trucks/{truck}', [FinanceController::class, 'destroyTruck']);
            });

            // Sales has its own permission: it is the one finance figure a
            // manager is routinely given without the ledger underneath it.
            Route::get('sales', [FinanceController::class, 'sales'])->middleware('permission:sales.view');
        });

        /* --------------------------------------------------------- Payroll */

        /**
         * Pay runs, and the four things you can do to one.
         *
         * Two permissions, and the line between them is money: reading a
         * payslip is `payroll.view` — HR answers for the roster and the
         * salaries on it — while building, approving and paying is
         * `payroll.manage`, because approving freezes what people are handed
         * and paying posts the entry to the books.
         *
         * Every write is a verb rather than a status PATCH, for the reason the
         * journal's are: approving tells the office, and paying writes a
         * journal entry. Neither is a field somebody sets.
         */
        Route::prefix('payroll')->group(function (): void {
            Route::middleware('permission:payroll.view')->group(function (): void {
                Route::get('/', [PayrollController::class, 'index']);
                /**
                 * The legal pay periods in a month — the 1st to the 15th and
                 * the 16th to the end of it.
                 *
                 * Before `{run}`, so `periods` is never read as a run id. Same
                 * ordering rule as `billing/statement`.
                 */
                Route::get('periods', [PayrollController::class, 'periods']);

                /**
                 * The firm's salary structure — its allowances and deductions.
                 *
                 * Before `{run}`, like `periods`, so `components` is never read
                 * as a run id.
                 *
                 * Under `payroll.view` rather than `hr.view`: what a firm pays
                 * on top of a basic is the same private business a payslip is,
                 * and the permission comment on `payroll.view` already draws
                 * that line — the roster and the pay are different rooms.
                 */
                Route::get('components', [PayComponentController::class, 'index']);

                /**
                 * Requests to move the firm's pay cutoff.
                 *
                 * Readable by anybody who can see payroll, because the person
                 * who filed one needs to know what happened to it — and a
                 * decision that only appeared on the administrator's screen
                 * would leave the office running payroll on a cutoff they had
                 * asked to change and had no way of knowing was still in force.
                 *
                 * `pending` before `{request}`, so it is never read as an id.
                 */
                Route::get('cutoff-requests', [PayrollCutoffRequestController::class, 'index']);
                Route::get('cutoff-requests/pending', [PayrollCutoffRequestController::class, 'pending']);

                Route::get('{run}', [PayrollController::class, 'show']);
            });

            /**
             * Filing and withdrawing a cutoff request — `payroll.manage`.
             *
             * Whoever builds, approves and pays the runs. They cannot change
             * the cutoff themselves (that is `company.manage`, below) and this
             * is the whole point of the endpoint: the person who notices gets a
             * way to say so that is not a conversation in a corridor.
             *
             * Declared before the `{run}` writes for the same reason the reads
             * are — `cutoff-requests` must not bind as a run id.
             */
            Route::middleware('permission:payroll.manage')->group(function (): void {
                Route::post('cutoff-requests', [PayrollCutoffRequestController::class, 'store']);
                Route::delete('cutoff-requests/{request}', [PayrollCutoffRequestController::class, 'withdraw']);
            });

            /**
             * Deciding one — `company.manage`, which is the permission that
             * could have made the change directly in the first place.
             *
             * Approving **applies** it, so the gate has to be the same one
             * `PATCH /company` sits behind. Anything looser would be a way
             * round that permission rather than a request to exercise it.
             */
            Route::middleware('permission:company.manage')->group(function (): void {
                Route::post('cutoff-requests/{request}/approve', [PayrollCutoffRequestController::class, 'approve']);
                Route::post('cutoff-requests/{request}/decline', [PayrollCutoffRequestController::class, 'decline']);
            });

            Route::middleware('permission:payroll.manage')->group(function (): void {
                /**
                 * Keeping the structure, and assigning it.
                 *
                 * `payroll.manage` rather than `hr.manage`, and the line is the
                 * same one the rest of this prefix draws: HR answers for the
                 * roster, and deciding that everybody gets another ₱2,000 a
                 * month is a money decision that lands in the books at the end
                 * of it. An office that wants its HR officer to do both gives
                 * them the permission.
                 */
                Route::post('components', [PayComponentController::class, 'store']);
                Route::match(['put', 'patch'], 'components/{component}', [PayComponentController::class, 'update']);
                Route::delete('components/{component}', [PayComponentController::class, 'destroy']);

                Route::post('assignments', [PayComponentController::class, 'assign']);
                Route::match(['put', 'patch'], 'assignments/{assignment}', [PayComponentController::class, 'updateAssignment']);
                Route::delete('assignments/{assignment}', [PayComponentController::class, 'unassign']);

                Route::post('/', [PayrollController::class, 'store']);
                // Not a PUT: rebuilding throws the lines away and works them
                // out again from the employee records as they now stand.
                Route::post('{run}/rebuild', [PayrollController::class, 'rebuild']);
                Route::match(['put', 'patch'], '{run}/lines/{line}', [PayrollController::class, 'adjust']);

                /**
                 * A deduction on one payslip, for this run only.
                 *
                 * The charge no assignment exists for — a uniform, a breakage,
                 * a cash advance against this fortnight. It goes on **named**
                 * rather than into the single "other deductions" figure,
                 * because "₱3,450 other" is a number the person holding the
                 * payslip cannot ask a question about.
                 *
                 * Deductions only. A taxable earning belongs in the tax base,
                 * and the withholding on the line was worked out when the run
                 * was built — so an earning goes on by working the run out
                 * again, which recomputes the lot.
                 */
                Route::post('{run}/lines/{line}/deductions', [PayrollController::class, 'addDeduction']);
                Route::delete('{run}/lines/{line}/deductions/{component}', [PayrollController::class, 'removeDeduction']);
                Route::post('{run}/approve', [PayrollController::class, 'approve']);
                Route::post('{run}/pay', [PayrollController::class, 'pay']);
                Route::delete('{run}', [PayrollController::class, 'destroy']);
            });
        });

        /* ------------------------------------------------------ Accounting */

        /**
         * The books proper: a chart of accounts, the general journal, and the
         * general ledger read off it.
         *
         * Its own pair of permissions rather than `finance.*`, and the line is
         * a real one. `finance.view` is the workbook — trip monitoring,
         * profitability, the quarterly summary — which a fleet manager reads
         * every day. The journal is the accountant's: posting to it decides
         * what every statement afterwards says, and voiding an entry is a
         * correction to the record itself. Plenty of people need the first and
         * should not have the second.
         *
         * Every static path is declared before the one that takes an id, so
         * `categories`, `types` and `trial-balance` are never read as one.
         */
        Route::prefix('accounting')->group(function (): void {
            Route::middleware('permission:accounting.view')->group(function (): void {
                // The chart. Not paginated — a chart is read whole.
                Route::get('accounts/types', [AccountController::class, 'types']);
                Route::get('accounts', [AccountController::class, 'index']);

                // The journal.
                Route::get('journal/categories', [JournalController::class, 'categories']);
                Route::get('journal', [JournalController::class, 'index']);

                /**
                 * The statements the books exist to produce.
                 *
                 * An income statement is a *period* and takes a range; a
                 * balance sheet is a *moment* and takes one date. Read-only,
                 * like the ledger — the way to change a figure on a statement
                 * is to write a journal entry.
                 */
                Route::get('statements/income', [StatementController::class, 'income']);
                Route::get('statements/balance-sheet', [StatementController::class, 'balanceSheet']);

                /**
                 * The general ledger. Read-only, all of it: nothing is ever
                 * written to a ledger, it *is* the journal sorted by account,
                 * and the way to change a figure on it is a journal entry.
                 */
                Route::get('ledger/trial-balance', [GeneralLedgerController::class, 'trialBalance']);
                Route::get('ledger/accounts/{account}', [GeneralLedgerController::class, 'show']);
                Route::get('ledger', [GeneralLedgerController::class, 'index']);

                // Last of the reads, so nothing above it is taken for an id.
                Route::get('accounts/{account}', [AccountController::class, 'show']);
                Route::get('journal/{entry}', [JournalController::class, 'show']);
            });

            Route::middleware('permission:accounting.manage')->group(function (): void {
                Route::post('accounts', [AccountController::class, 'store']);
                Route::match(['put', 'patch'], 'accounts/{account}', [AccountController::class, 'update']);
                Route::delete('accounts/{account}', [AccountController::class, 'destroy']);

                Route::post('journal', [JournalController::class, 'store']);
                Route::match(['put', 'patch'], 'journal/{entry}', [JournalController::class, 'update']);
                Route::delete('journal/{entry}', [JournalController::class, 'destroy']);

                /**
                 * Both verbs rather than status PATCHes.
                 *
                 * Posting stamps who did it and when, checks the entry
                 * balances first, and tells the office. Voiding takes a reason
                 * with it and leaves the row where it is. A status field that
                 * did any of that when set to one particular value would hide
                 * all of it.
                 */
                Route::post('journal/{entry}/post', [JournalController::class, 'post']);
                Route::post('journal/{entry}/void', [JournalController::class, 'void']);
            });
        });

        // Other Expenses. The category routes and the report are declared
        // before the resource so none of them is read as an expense id.
        Route::prefix('expenses')->group(function (): void {
            Route::middleware('permission:expenses.view')->group(function (): void {
                Route::get('report', [ExpenseController::class, 'report']);
                Route::get('categories', [ExpenseController::class, 'categories']);
            });

            Route::middleware('permission:expenses.manage')->group(function (): void {
                Route::post('categories', [ExpenseController::class, 'storeCategory']);
                Route::match(['put', 'patch'], 'categories/{category}', [ExpenseController::class, 'updateCategory']);
                Route::delete('categories/{category}', [ExpenseController::class, 'destroyCategory']);
            });
        });

        Route::get('expenses', [ExpenseController::class, 'index'])->middleware('permission:expenses.view');
        Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->middleware('permission:expenses.view');

        Route::middleware('permission:expenses.manage')->group(function (): void {
            Route::post('expenses', [ExpenseController::class, 'store']);
            Route::match(['put', 'patch'], 'expenses/{expense}', [ExpenseController::class, 'update']);
            Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy']);
        });

        // The ledger is one set of endpoints reached two ways — the office
        // managing the sheet, and a driver filing the day's figures from the
        // cab. Either permission opens it; requiring both would lock out each
        // of them in turn.
        Route::get('ledger', [FinanceController::class, 'index'])
            ->middleware('permission:finance.view,finance.write');

        Route::middleware('permission:finance.manage,finance.write')->group(function (): void {
            Route::post('ledger', [FinanceController::class, 'store']);
            Route::match(['put', 'patch'], 'ledger/{ledger}', [FinanceController::class, 'update']);
            Route::delete('ledger/{ledger}', [FinanceController::class, 'destroy']);
        });

        /* -------------------------------------------------------- Rate card */

        // Declared before the resource so `pricing/zones/quote` and
        // `pricing/zones/diesel` are never read as a zone id.
        Route::prefix('pricing')->group(function (): void {
            // Quoting is a read of the card, not a change to it — the desk
            // needs it to answer a customer on the phone.
            Route::post('quote', [PricingController::class, 'quote'])->middleware('permission:pricing.view');
            Route::get('diesel', [PricingController::class, 'diesel'])->middleware('permission:pricing.view');
            Route::post('diesel', [PricingController::class, 'storeDiesel'])->middleware('permission:pricing.manage');

            /**
             * The firm's plain distance card — the lines belonging to no zone.
             *
             * For most hauliers this *is* the rate card: "450 km is ₱5,000",
             * with no place to name. The zone editor beside it is for the
             * firms that genuinely price one route differently from another,
             * and is now a refinement rather than the only way in.
             */
            Route::get('card', [PricingController::class, 'card'])->middleware('permission:pricing.view');
            Route::put('card', [PricingController::class, 'saveCard'])->middleware('permission:pricing.manage');

            /**
             * The kinds of unit the firm runs — Dry Goods, Freezer, Flatbed.
             *
             * The card's second dimension. Reefer work carries a premium that
             * has nothing to do with distance, and a card that could only
             * price distance forced the desk to quote it off-system.
             */
            Route::get('truck-categories', [PricingController::class, 'truckCategories'])
                ->middleware('permission:pricing.view');

            Route::middleware('permission:pricing.manage')->group(function (): void {
                Route::post('truck-categories', [PricingController::class, 'storeTruckCategory']);
                Route::match(['put', 'patch'], 'truck-categories/{truckCategory}', [PricingController::class, 'updateTruckCategory']);
                Route::delete('truck-categories/{truckCategory}', [PricingController::class, 'destroyTruckCategory']);
            });
        });

        Route::get('pricing/zones', [PricingController::class, 'index'])->middleware('permission:pricing.view');
        Route::get('pricing/zones/{zone}', [PricingController::class, 'show'])->middleware('permission:pricing.view');

        Route::middleware('permission:pricing.manage')->group(function (): void {
            Route::post('pricing/zones', [PricingController::class, 'store']);
            Route::match(['put', 'patch'], 'pricing/zones/{zone}', [PricingController::class, 'update']);
            Route::delete('pricing/zones/{zone}', [PricingController::class, 'destroy']);
        });

        /* -------------------------------------------------------- Business */

        Route::middleware('permission:customers.view')->group(function (): void {
            Route::get('customers', [CustomerController::class, 'index']);
            Route::get('customers/{customer}', [CustomerController::class, 'show']);
            Route::get('customers/{customer}/history', [CustomerController::class, 'history']);
        });

        Route::middleware('permission:customers.manage')->group(function (): void {
            Route::post('customers', [CustomerController::class, 'store']);
            Route::match(['put', 'patch'], 'customers/{customer}', [CustomerController::class, 'update']);
            Route::delete('customers/{customer}', [CustomerController::class, 'destroy']);
        });

        /* -------------------------------------------- Customer self-service */

        // The customer's own screens, scoped to their `customers` row exactly
        // as the driver routes above are scoped to a `drivers` row. No id in
        // any path is theirs to change, which is why these are not a filter on
        // `trips` — one forgotten `where` there would expose the whole board.
        Route::prefix('portal')->middleware('permission:portal.view')->group(function (): void {
            Route::get('summary', [PortalController::class, 'summary']);
            Route::get('invoices', [PortalController::class, 'invoices']);

            /**
             * Who could pick this up — the screen a request now starts on.
             *
             * The one read a customer makes that is about the platform rather
             * than about one company's books: the active, pinned hauliers near
             * the load, nearest first, plus whichever they already deal with.
             * `CarrierDirectory` is the whole of what it can see, and that is a
             * name, a yard, a phone number and how many units are free.
             *
             * Before `requests/{trip}`, so "carriers" is never read as a trip
             * id.
             */
            Route::get('carriers', [PortalController::class, 'carriers']);

            /**
             * Who could carry this load, inside one haulier.
             *
             * The fleet, and the vetted truckers near the pickup. Distinct from
             * `carriers` above, which lists companies across the platform: this
             * is how a customer chooses between "send it with Cargo Rush" and
             * "send it with that man whose truck is twenty minutes away".
             *
             * Before `requests/{tripId}`, so "haulers" is never read as a trip
             * id.
             */
            Route::get('haulers', [PortalController::class, 'haulers']);

            Route::get('requests', [PortalController::class, 'index']);
            /**
             * Deliberately `{tripId}` and not `{trip}`.
             *
             * `trip` is bound to the model platform-wide (`DomainServiceProvider`),
             * and a bound trip resolves under the *caller's* company — which
             * would 404 a delivery the customer can plainly see on their own
             * list, because another carrier is hauling it. The name is what
             * turns the binding off, so the id arrives as a string and
             * `PortalService` asks each of the customer's carriers in turn.
             */
            Route::get('requests/{tripId}', [PortalController::class, 'show']);

            // Booking is its own permission: a firm can be given read-only
            // access to its own account without being able to raise work.
            Route::post('requests', [PortalController::class, 'store'])
                ->middleware('permission:portal.request');
        });

        // `totals` and `aging` before the resource, so neither is read as an
        // invoice id.
        Route::middleware('permission:billing.view')->group(function (): void {
            Route::get('billing/totals', [BillingController::class, 'totals']);
            // What is outstanding, by how late it is. The report a collections
            // call is made from.
            Route::get('billing/aging', [BillingController::class, 'aging']);
            /**
             * The list as a spreadsheet, honouring the same filters as the
             * list itself — before `billing/{invoice}` so `export` is never
             * read as an invoice id.
             */
            Route::get('billing/export', [BillingController::class, 'export']);
            Route::get('billing', [BillingController::class, 'index']);
            /**
             * One firm's running account — the collections document.
             *
             * Before `billing/{invoice}` so `statement` is never read as an
             * invoice id, and it takes a *customer* rather than an invoice:
             * a statement is about the account, not about one document on it.
             */
            Route::get('billing/statement/{customer}', [BillingController::class, 'statement']);
            Route::get('billing/{invoice}', [BillingController::class, 'show']);
            /**
             * The invoice as a document: the issuer, both TINs, the haul, the
             * tax lines, the amount in words and every payment against it.
             * `billing.view`, because printing an invoice does not change it.
             */
            Route::get('billing/{invoice}/document', [BillingController::class, 'document']);
        });

        Route::middleware('permission:billing.manage')->group(function (): void {
            Route::post('billing', [BillingController::class, 'store']);
            Route::match(['put', 'patch'], 'billing/{invoice}', [BillingController::class, 'update']);
            Route::delete('billing/{invoice}', [BillingController::class, 'destroy']);
            Route::post('billing/{invoice}/settle', [BillingController::class, 'settle']);
        });

        // Payments, as records of their own — a payment has its own date and
        // reference and may settle several documents, none of which fits on an
        // invoice. Reading them is `billing.view`; recording one is money
        // moving, so it needs `billing.manage`.
        Route::get('payments', [PaymentController::class, 'index'])->middleware('permission:billing.view');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:billing.view');

        Route::middleware('permission:billing.manage')->group(function (): void {
            Route::post('payments', [PaymentController::class, 'store']);
            Route::delete('payments/{payment}', [PaymentController::class, 'destroy']);
        });

        /* ------------------------------------------------- The company */

        // The caller's own company — no id in any path, scoped to the account
        // exactly as the driver and customer routes are. `company.manage` is
        // its own permission rather than `access.manage`: changing the logo is
        // not handing out keys.
        Route::prefix('company')->middleware('permission:company.manage')->group(function (): void {
            Route::get('/', [CompanyController::class, 'show']);

            /**
             * The company's own details, including where its yard is.
             *
             * A PATCH rather than a PUT: the only field most firms will ever
             * touch here is the map pin, which is asked for at registration and
             * is the one thing a company that signed up before the carrier list
             * existed has no other way to set. Sending the whole record to move
             * a pin would invite a client to blank the contact details on the
             * way past.
             */
            Route::match(['put', 'patch'], '/', [CompanyController::class, 'update']);

            Route::post('logo', [CompanyController::class, 'storeLogo']);
            Route::delete('logo', [CompanyController::class, 'destroyLogo']);
        });

        /* -------------------------------------------------- Access control */

        // Roles, what each reaches, and the job titles behind them. The
        // permission vocabulary is read-only: a permission is only real if code
        // checks for it, so one invented here would gate nothing.
        Route::prefix('access')->group(function (): void {
            // `access.view` also covers the two lists the HR forms read — an
            // HR officer registering a hire needs the positions to pick from
            // and the roles to offer, so `hr.manage` opens the reads too.
            Route::middleware('permission:access.view,hr.manage')->group(function (): void {
                Route::get('permissions', [AccessController::class, 'permissions']);
                Route::get('roles', [AccessController::class, 'roles']);
                Route::get('positions', [AccessController::class, 'positions']);
            });

            // Changing what a role reaches is its own permission, held by the
            // administrator and nobody else by default. An HR officer runs the
            // roster; they do not get to grant themselves the ledger.
            Route::middleware('permission:access.manage')->group(function (): void {
                Route::post('roles', [AccessController::class, 'storeRole']);
                Route::match(['put', 'patch'], 'roles/{role}', [AccessController::class, 'updateRole']);
                Route::delete('roles/{role}', [AccessController::class, 'destroyRole']);

                Route::post('positions', [AccessController::class, 'storePosition']);
                Route::match(['put', 'patch'], 'positions/{position}', [AccessController::class, 'updatePosition']);
                Route::delete('positions/{position}', [AccessController::class, 'destroyPosition']);
            });
        });

        /* -------------------------------------------------------------- HR */

        // Declared before the resources so `employees/overview` and
        // `applicants/pipeline` are never read as ids.
        Route::middleware('permission:hr.view')->group(function (): void {
            Route::get('employees/overview', [EmployeeController::class, 'overview']);
            Route::get('applicants/pipeline', [ApplicantController::class, 'pipeline']);
            Route::get('employees', [EmployeeController::class, 'index']);
            Route::get('employees/{employee}', [EmployeeController::class, 'show']);
            Route::get('employees/{employee}/modules', [EmployeeController::class, 'modules']);

            /**
             * The allowances and deductions this person is on.
             *
             * Under the employee because that is the screen it belongs to, and
             * gated on `payroll.view` rather than the `hr.view` around it: what
             * somebody is paid on top of their basic is the private business a
             * payslip is. An HR officer who runs the roster does not
             * automatically see everybody's allowances — the same line the
             * payroll prefix draws, drawn here too rather than quietly not.
             */
            Route::get('employees/{employee}/pay-components', [PayComponentController::class, 'forEmployee'])
                ->middleware('permission:payroll.view');

            /**
             * The store tab — the mini-mart *pautang* this person owes against.
             *
             * `payroll.view` to read and `payroll.manage` to write, for the
             * same reason as the pay components above: what somebody owes the
             * firm and what comes off their payslip is pay information, not
             * roster information. An HR officer running the roster does not
             * automatically see everybody's tab.
             *
             * Writing is `payroll.manage` rather than a permission of its own.
             * A tab is a deduction from wages by another name, and whoever may
             * decide what comes off a payslip is exactly who should be able to
             * put a line on it.
             */
            /**
             * What this person has been paid, over time.
             *
             * Pay is a history rather than a column — one row per agreement,
             * with the day it starts on — so this is the screen that answers
             * "what was she on last year" and "when did that go up".
             *
             * On the payroll permissions for the same reason as the two
             * above: what somebody earns is pay information rather than roster
             * information. `payroll.view` to read and `payroll.manage` to
             * write, so an HR officer can see what somebody is on while
             * agreeing a new figure stays with whoever runs the payroll.
             *
             * No update and no delete beyond taking back a row typed by
             * mistake. A rise appends, because the row a pay run was built from
             * has to still be there when the draft is rebuilt next week.
             */
            Route::get('employees/{employee}/contracts', [ContractController::class, 'index'])
                ->middleware('permission:payroll.view');
            Route::post('employees/{employee}/contracts', [ContractController::class, 'store'])
                ->middleware('permission:payroll.manage');
            Route::delete('employees/{employee}/contracts/{contract}', [ContractController::class, 'destroy'])
                ->middleware('permission:payroll.manage');

            Route::get('employees/{employee}/store-credits', [StoreCreditController::class, 'index'])
                ->middleware('permission:payroll.view');
            Route::post('employees/{employee}/store-credits', [StoreCreditController::class, 'store'])
                ->middleware('permission:payroll.manage');
            Route::delete('store-credits/{storeCredit}', [StoreCreditController::class, 'destroy'])
                ->middleware('permission:payroll.manage');
            Route::get('applicants', [ApplicantController::class, 'index']);
            Route::get('applicants/{applicant}', [ApplicantController::class, 'show']);
        });

        Route::middleware('permission:hr.manage')->group(function (): void {
            Route::post('employees', [EmployeeController::class, 'store']);
            Route::match(['put', 'patch'], 'employees/{employee}', [EmployeeController::class, 'update']);
            Route::delete('employees/{employee}', [EmployeeController::class, 'destroy']);

            // The account is a separate action from the record: plenty of staff
            // have no login, and creating one is a decision with a password.
            Route::post('employees/{employee}/account', [EmployeeController::class, 'createAccount']);
            Route::post('employees/{employee}/role', [EmployeeController::class, 'assignRole']);
            Route::put('employees/{employee}/modules', [EmployeeController::class, 'assignModules']);

            Route::post('applicants', [ApplicantController::class, 'store']);
            Route::match(['put', 'patch'], 'applicants/{applicant}', [ApplicantController::class, 'update']);
            Route::delete('applicants/{applicant}', [ApplicantController::class, 'destroy']);
            Route::post('applicants/{applicant}/stage', [ApplicantController::class, 'stage']);
            Route::post('applicants/{applicant}/hire', [ApplicantController::class, 'hire']);
        });

        // Leave and undertime. Deciding is a verb, not a status PATCH: it
        // records who decided and when, and a status a client could set
        // directly would let a request be approved by nobody.
        Route::prefix('hr')->group(function (): void {
            Route::middleware('permission:hr.view')->group(function (): void {
                Route::get('time-off', [TimeOffController::class, 'overview']);
                Route::get('leave', [TimeOffController::class, 'leaveIndex']);
                Route::get('undertime', [TimeOffController::class, 'undertimeIndex']);
                Route::get('performance', [PerformanceController::class, 'index']);
                Route::get('performance/{employee}', [PerformanceController::class, 'show']);
            });

            Route::middleware('permission:hr.manage')->group(function (): void {
                Route::post('leave', [TimeOffController::class, 'storeLeave']);
                Route::match(['put', 'patch'], 'leave/{leave}', [TimeOffController::class, 'updateLeave']);
                Route::post('leave/{leave}/decision', [TimeOffController::class, 'decideLeave']);
                Route::post('leave/{leave}/withdraw', [TimeOffController::class, 'withdrawLeave']);
                Route::delete('leave/{leave}', [TimeOffController::class, 'destroyLeave']);

                Route::post('undertime', [TimeOffController::class, 'storeUndertime']);
                Route::match(['put', 'patch'], 'undertime/{undertime}', [TimeOffController::class, 'updateUndertime']);
                Route::post('undertime/{undertime}/decision', [TimeOffController::class, 'decideUndertime']);
                Route::post('undertime/{undertime}/withdraw', [TimeOffController::class, 'withdrawUndertime']);
                Route::delete('undertime/{undertime}', [TimeOffController::class, 'destroyUndertime']);
            });
        });

        /* --------------------------------------------------------- Support */

        /**
         * The driver's own incidents — reported from the road, on `cargoApp`.
         *
         * Declared before `incidents/{incident}` so `mine` is never read as an
         * id. Gated on `incidents.write`, the driver's half of this module:
         * report what happened on your own run. The log, editing a write-up and
         * closing one out are the office's and stay below.
         *
         * Both are scoped to the caller and carry no ids — see
         * `DriverIncidentController`.
         */
        Route::middleware('permission:incidents.write')->group(function (): void {
            Route::get('incidents/mine', [DriverIncidentController::class, 'index']);
            Route::post('incidents/report', [DriverIncidentController::class, 'store']);
        });

        Route::middleware('permission:incidents.view')->group(function (): void {
            Route::get('incidents', [IncidentController::class, 'index']);
            Route::get('incidents/{incident}', [IncidentController::class, 'show']);
        });

        Route::middleware('permission:incidents.manage')->group(function (): void {
            Route::post('incidents', [IncidentController::class, 'store']);
            Route::match(['put', 'patch'], 'incidents/{incident}', [IncidentController::class, 'update']);
            Route::delete('incidents/{incident}', [IncidentController::class, 'destroy']);
        });

        // The feed is scoped to the caller inside the controller, so this only
        // has to establish that they are entitled to a feed at all.
        Route::middleware('permission:notifications.view')->group(function (): void {
            Route::get('notifications', [NotificationController::class, 'index']);
            Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
            Route::post('notifications/{notification}/read', [NotificationController::class, 'read']);
        });

        /* --------------------------------------------- Mobile-only capture */

        // One permission for the whole module: a pre-trip check is something a
        // driver does, and there is no separate audience that only reads them.
        Route::middleware('permission:inspection.write')->group(function (): void {
            Route::get('inspections/checklist', [InspectionController::class, 'checklist']);
            Route::get('inspections', [InspectionController::class, 'index']);
            Route::post('inspections', [InspectionController::class, 'store']);
            Route::get('inspections/vehicles/{vehicle}/maintenance', [InspectionController::class, 'maintenance']);
        });
    });
});
