<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PaymentSourceSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TruckCategorySeeder;
use Database\Seeders\TruckSizeSeeder;

class CompanyProvisioner
{
    public function __construct(private readonly Tenant $tenant) {}

    public function provision(Company $company): void
    {
        $this->platform();

        $this->tenant->use($company, function (): void {
            (new RoleSeeder)->run();
            (new PositionSeeder)->run();
            (new ExpenseCategorySeeder)->run();
            (new TruckCategorySeeder)->run();
            (new TruckSizeSeeder)->run();
            (new ChartOfAccountsSeeder)->run();
            (new PaymentSourceSeeder)->run();
        });
    }

    private function platform(): void
    {
        $this->tenant->across(function (): void {
            (new PermissionSeeder)->run();
            (new NavigationSeeder)->run();
        });
    }
}
