<?php

declare(strict_types=1);

namespace App\Domain\Shared\Providers;

use App\Domain\Billing\Console\QuoteUnpricedTripsCommand;
use App\Domain\Billing\Console\ReconcileOverdueInvoicesCommand;
use App\Domain\Billing\Console\RequoteInvoicesCommand;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Dispatch\Models\DispatchRecord;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Console\BackfillLedgerRowsCommand;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Fuel\Models\FuelRecord;
use App\Domain\Hr\Models\Applicant;
use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Models\LeaveRequest;
use App\Domain\Hr\Models\UndertimeRequest;
use App\Domain\Identity\Console\CreateUserCommand;
use App\Domain\Identity\Models\Position;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Incident\Models\Incident;
use App\Domain\Notification\Models\NotificationItem;
use App\Domain\Payroll\Console\DemoPayrollCommand;
use App\Domain\Payroll\Models\StoreCredit;
use App\Domain\Pricing\Console\LoadSubsidyCardCommand;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trip\Console\ReconcileOverdueTripsCommand;
use App\Domain\Trip\Console\ReleaseDueTripsCommand;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Wires the domain modules into the framework.
 *
 * Models live under `app/Domain/<Module>/Models` rather than `app/Models`, so
 * the two conventions Laravel infers from a default layout — route-model
 * binding and factory discovery — have to be declared once, here, instead of
 * being re-stated on every model.
 */
class DomainServiceProvider extends ServiceProvider
{
    /**
     * Route parameter name => model. `{trip}` resolves a Trip, and so on.
     *
     * @var array<string, class-string<Model>>
     */
    private const BINDINGS = [
        'trip' => Trip::class,
        'driver' => Driver::class,
        'vehicle' => Vehicle::class,
        'customer' => Customer::class,
        'dispatch' => DispatchRecord::class,
        'delivery' => DeliveryLog::class,
        'fuel' => FuelRecord::class,
        'invoice' => Invoice::class,
        'incident' => Incident::class,
        'notification' => NotificationItem::class,
        'ledger' => LedgerEntry::class,
        'truck' => Truck::class,
        'expense' => Expense::class,
        'category' => ExpenseCategory::class,
        /**
         * Not `category`, which is taken.
         *
         * These bindings are global by parameter *name*, so two modules using
         * `{category}` would both resolve to whichever model is listed here —
         * and the second one 404s on every id, which reads as a missing record
         * rather than as a wiring mistake. The route says `{truckCategory}` for
         * that reason and not as a style preference.
         */
        'truckCategory' => TruckCategory::class,
        'user' => User::class,
        'zone' => PricingZone::class,
        'employee' => Employee::class,
        'applicant' => Applicant::class,
        'leave' => LeaveRequest::class,
        'undertime' => UndertimeRequest::class,
        'role' => Role::class,
        'position' => Position::class,
        'company' => Company::class,
        'payment' => Payment::class,
        // Not `credit`, for the reason `truckCategory` is not `category`:
        // these bind by parameter *name* across every module at once.
        'storeCredit' => StoreCredit::class,
    ];

    public function register(): void
    {
        /**
         * Which company the code currently running belongs to.
         *
         * A singleton because it has to be *the same object* everywhere: the
         * middleware sets it, and the global scope on twenty-eight models reads
         * it on every query. A fresh instance per resolution would mean the
         * scope reading an empty one and filtering nothing, which is the one
         * failure mode this whole design exists to prevent.
         *
         * Scoped to the request, so nothing survives into the next one. Under
         * Octane or a queue worker the process is reused, and a company left
         * behind by the last request would silently answer the next.
         */
        $this->app->scoped(Tenant::class);
    }

    public function boot(): void
    {
        // Commands live with their module rather than in `app/Console`, so
        // they are not auto-discovered and have to be named here.
        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateUserCommand::class,
                BackfillLedgerRowsCommand::class,
                ReleaseDueTripsCommand::class,
                ReconcileOverdueTripsCommand::class,
                ReconcileOverdueInvoicesCommand::class,
                // A one-off repair rather than a sweep: nothing schedules it.
                RequoteInvoicesCommand::class,
                QuoteUnpricedTripsCommand::class,
                // A published rate table, loaded into one named company on
                // purpose. Never scheduled, and never across every company:
                // prices are one haulier's business with its principal.
                LoadSubsidyCardCommand::class,
                // Demo data, run on purpose and never as part of a deploy.
                DemoPayrollCommand::class,
            ]);
        }

        foreach (self::BINDINGS as $parameter => $model) {
            Route::model($parameter, $model);
        }

        $this->rateLimits();

        // Permissions are role-derived strings, not policy classes, so one
        // `before` hook answers every `can()` in the app. Returning null on a
        // miss lets a real policy still have its say later.
        Gate::before(static function (User $user, string $ability): ?bool {
            return $user->hasPermission($ability) ? true : null;
        });

        // Factories are named for the model, not for its namespace, so one
        // resolver covers every module.
        Factory::guessFactoryNamesUsing(
            static fn (string $modelName): string => sprintf('Database\Factories\%sFactory', class_basename($modelName))
        );
    }

    /**
     * How often a caller may knock.
     *
     * Laravel does not throttle the API group for you — `withRouting` builds it
     * without a limiter — so until this existed `POST /login` and
     * `POST /register` were unmetered. On a single-tenant install behind an
     * office firewall that was survivable. With a public registration endpoint
     * it is not: an unmetered login is an invitation to work through a list of
     * leaked passwords, and an unmetered register is unlimited companies.
     *
     * Three limits, because the three things being protected fail differently.
     */
    private function rateLimits(): void
    {
        /**
         * Everything behind `auth:sanctum`.
         *
         * Keyed on the account rather than the address it came from: a fleet
         * office is a dozen people behind one NAT, and an IP limit would have
         * them throttling each other. Generous, because the dashboard alone
         * fires five calls and a driver's handset reports position every
         * minute — this is a runaway-client guard, not a usage policy.
         */
        RateLimiter::for('api', static fn (Request $request) => Limit::perMinute(300)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        /**
         * Signing in.
         *
         * Keyed on **the address being tried plus the caller's IP**. Keying on
         * the IP alone lets one host work through a thousand accounts at five
         * a minute each; keying on the email alone lets anybody lock a known
         * user out of their own account by failing their login on purpose. The
         * pair costs an attacker a fresh IP for every account they want to
         * try, and cannot be used as a denial of service against one person.
         */
        RateLimiter::for('login', static fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));

        /**
         * Registering — a company on the web, a customer in the app.
         *
         * Per hour, per IP, because this is the one endpoint where a
         * *successful* request costs the platform real storage: a company, a
         * set of roles and a permanent row. So unlike login, where only
         * failures are worth counting, the successes are metered too.
         *
         * Twenty rather than five, and the reason is the arithmetic of a form
         * that is being filled in wrong. The middleware counts every attempt,
         * including the ones the validator rejects — a mistyped address, a
         * password on the breached list, a mismatched confirmation — so a tight
         * cap does not meter *registrations*, it locks somebody out of the
         * sign-up form for an hour while they are still trying to use it. Which
         * is exactly what it did. Twenty is still bounded, and twenty companies
         * an hour from one address is not a threat anybody needs a smaller
         * number to survive.
         *
         * Both clients say what a 429 means rather than printing the status,
         * because "the server refused that request (429)" reads as a broken
         * form and somebody who believes the form is broken keeps pressing.
         */
        RateLimiter::for('register', static fn (Request $request) => Limit::perHour(20)->by($request->ip()));

        /**
         * The public carrier list.
         *
         * The one read anybody can make without an account, because a shipper
         * has to see who is near them *before* they have somewhere to sign in
         * to. Per minute and per IP, generously — the sign-up screen refetches
         * as somebody drags the map — but metered, because it is a query over
         * every company on the platform and the only unauthenticated read there
         * is.
         */
        RateLimiter::for('carriers', static fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
