<?php

declare(strict_types=1);

use App\Domain\Billing\Controllers\BillingController;
use App\Domain\Customer\Controllers\CustomerController;
use App\Domain\Customer\Controllers\PortalController;
use App\Domain\Dashboard\Controllers\DashboardController;
use App\Domain\Delivery\Controllers\DeliveryController;
use App\Domain\Dispatch\Controllers\DispatchController;
use App\Domain\Driver\Controllers\DriverController;
use App\Domain\Finance\Controllers\ExpenseController;
use App\Domain\Finance\Controllers\FinanceController;
use App\Domain\Fuel\Controllers\FuelController;
use App\Domain\Gps\Controllers\GpsController;
use App\Domain\Hr\Controllers\ApplicantController;
use App\Domain\Hr\Controllers\EmployeeController;
use App\Domain\Hr\Controllers\PerformanceController;
use App\Domain\Hr\Controllers\TimeOffController;
use App\Domain\Identity\Controllers\AccessController;
use App\Domain\Identity\Controllers\AuthController;
use App\Domain\Identity\Controllers\MeController;
use App\Domain\Identity\Controllers\NavigationController;
use App\Domain\Incident\Controllers\IncidentController;
use App\Domain\Inspection\Controllers\InspectionController;
use App\Domain\Notification\Controllers\NotificationController;
use App\Domain\Pricing\Controllers\PricingController;
use App\Domain\Trip\Controllers\DriverTripController;
use App\Domain\Trip\Controllers\TripController;
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

    Route::post('login', [AuthController::class, 'login'])->name('login');

    /* ---------------------------------------------------- Authenticated */

    Route::middleware('auth:sanctum')->group(function (): void {

        Route::post('logout', [AuthController::class, 'logout']);

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

        Route::middleware('permission:vehicles.view')->group(function (): void {
            Route::get('vehicles', [VehicleController::class, 'index']);
            Route::get('vehicles/{vehicle}', [VehicleController::class, 'show']);
            Route::get('vehicles/{vehicle}/maintenance', [VehicleController::class, 'maintenance']);
        });

        Route::middleware('permission:vehicles.manage')->group(function (): void {
            Route::post('vehicles', [VehicleController::class, 'store']);
            Route::match(['put', 'patch'], 'vehicles/{vehicle}', [VehicleController::class, 'update']);
            Route::delete('vehicles/{vehicle}', [VehicleController::class, 'destroy']);
            Route::post('vehicles/{vehicle}/status', [VehicleController::class, 'status']);
        });

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
            Route::get('requests', [PortalController::class, 'index']);
            Route::get('requests/{trip}', [PortalController::class, 'show']);

            // Booking is its own permission: a firm can be given read-only
            // access to its own account without being able to raise work.
            Route::post('requests', [PortalController::class, 'store'])
                ->middleware('permission:portal.request');
        });

        Route::middleware('permission:billing.view')->group(function (): void {
            Route::get('billing/totals', [BillingController::class, 'totals']);
            Route::get('billing', [BillingController::class, 'index']);
            Route::get('billing/{invoice}', [BillingController::class, 'show']);
        });

        Route::middleware('permission:billing.manage')->group(function (): void {
            Route::post('billing', [BillingController::class, 'store']);
            Route::match(['put', 'patch'], 'billing/{invoice}', [BillingController::class, 'update']);
            Route::delete('billing/{invoice}', [BillingController::class, 'destroy']);
            Route::post('billing/{invoice}/settle', [BillingController::class, 'settle']);
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
