<?php

declare(strict_types=1);

namespace App\Domain\Shared\Providers;

use App\Domain\Accounting\Console\BackfillAutoPostingsCommand;
use App\Domain\Accounting\Observers\AutoPostingObserver;
use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Models\AssetCategory;
use App\Domain\Billing\Console\QuoteUnpricedTripsCommand;
use App\Domain\Billing\Console\ReconcileOverdueInvoicesCommand;
use App\Domain\Billing\Console\RequoteInvoicesCommand;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\DeliveryLog;
use App\Domain\Dispatch\Models\DispatchRecord;
use App\Domain\Driver\Models\Driver;
use App\Domain\Finance\Console\BackfillLedgerRowsCommand;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\Truck;
use App\Domain\Fuel\Console\PostFuelFillsCommand;
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
use App\Domain\Payroll\Models\PayRun;
use App\Domain\Payroll\Models\StoreCredit;
use App\Domain\Pricing\Console\LoadSubsidyCardCommand;
use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Pricing\Models\TruckCategory;
use App\Domain\Shared\Console\PrefixTablesCommand;
use App\Domain\Shared\Support\ChangeLog;
use App\Domain\Supplier\Models\SupplierCategory;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use App\Domain\Trip\Console\ReconcileOverdueTripsCommand;
use App\Domain\Trip\Console\ReleaseDueTripsCommand;
use App\Domain\Trip\Console\RemeasureTripsCommand;
use App\Domain\Trip\Models\Trip;
use App\Domain\Trucker\Models\Trucker;
use App\Domain\Trucker\Models\WalletEntry;
use App\Domain\Vehicle\Console\ChargeTruckRentCommand;
use App\Domain\Vehicle\Models\MaintenanceJob;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class DomainServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string<Model>> */
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
        'supplierCategory' => SupplierCategory::class,
        'asset' => Asset::class,
        'assetCategory' => AssetCategory::class,
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
        'storeCredit' => StoreCredit::class,
        'trucker' => Trucker::class,
    ];

    public function register(): void
    {
        $this->app->scoped(Tenant::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateUserCommand::class,
                BackfillLedgerRowsCommand::class,
                ReleaseDueTripsCommand::class,
                ReconcileOverdueTripsCommand::class,
                ReconcileOverdueInvoicesCommand::class,
                RequoteInvoicesCommand::class,
                QuoteUnpricedTripsCommand::class,
                RemeasureTripsCommand::class,
                LoadSubsidyCardCommand::class,
                ChargeTruckRentCommand::class,
                PostFuelFillsCommand::class,
                DemoPayrollCommand::class,
                BackfillAutoPostingsCommand::class,
                PrefixTablesCommand::class,
            ]);
        }

        $this->autoPosting();
        $this->changeLog();

        foreach (self::BINDINGS as $parameter => $model) {
            Route::model($parameter, $model);
        }

        $this->rateLimits();

        Gate::before(static function (User $user, string $ability): ?bool {
            return $user->hasPermission($ability) ? true : null;
        });

        Factory::guessFactoryNamesUsing(
            static fn (string $modelName): string => sprintf('Database\Factories\%sFactory', class_basename($modelName))
        );
    }

    private function changeLog(): void
    {
        Event::listen(
            array_map(static fn (string $action): string => "eloquent.{$action}: *", ChangeLog::ACTIONS),
            static function (string $event, array $payload): void {
                if ($payload[0] instanceof Model) {
                    ChangeLog::record(Str::between($event, 'eloquent.', ':'), $payload[0]);
                }
            },
        );
    }

    private function autoPosting(): void
    {
        foreach ([
            LedgerEntry::class,
            Invoice::class,
            Payment::class,
            PaymentAllocation::class,
            Expense::class,
            ExpenseCategory::class,
            FuelRecord::class,
            WalletEntry::class,
            PayRun::class,
            MaintenanceJob::class,
            Trip::class,
        ] as $model) {
            $model::observe(AutoPostingObserver::class);
        }
    }

    private function rateLimits(): void
    {
        RateLimiter::for('api', static fn (Request $request) => Limit::perMinute(300)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('login', static fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('register', static fn (Request $request) => Limit::perHour(20)->by($request->ip()));

        RateLimiter::for('carriers', static fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
