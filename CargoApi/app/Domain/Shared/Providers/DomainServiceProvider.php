<?php

declare(strict_types=1);

namespace App\Domain\Shared\Providers;

use App\Domain\Billing\Console\QuoteUnpricedTripsCommand;
use App\Domain\Billing\Console\ReconcileOverdueInvoicesCommand;
use App\Domain\Billing\Models\Invoice;
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
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Trip\Console\ReconcileOverdueTripsCommand;
use App\Domain\Trip\Console\ReleaseDueTripsCommand;
use App\Domain\Trip\Models\Trip;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

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
        'user' => User::class,
        'zone' => PricingZone::class,
        'employee' => Employee::class,
        'applicant' => Applicant::class,
        'leave' => LeaveRequest::class,
        'undertime' => UndertimeRequest::class,
        'role' => Role::class,
        'position' => Position::class,
    ];

    public function register(): void
    {
        //
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
                QuoteUnpricedTripsCommand::class,
            ]);
        }

        foreach (self::BINDINGS as $parameter => $model) {
            Route::model($parameter, $model);
        }

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
}
