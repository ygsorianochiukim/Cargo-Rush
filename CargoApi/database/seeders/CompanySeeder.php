<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\CompanyProvisioner;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function __construct(private readonly CompanyProvisioner $provisioner) {}

    public function run(): void
    {
        $companies = Company::query()->orderBy('created_at')->get();

        if ($companies->isEmpty()) {
            $this->command?->warn('No companies on this install — register one at POST /api/v1/register.');

            return;
        }

        foreach ($companies as $company) {
            $this->provisioner->provision($company);
        }

        $this->command?->info(sprintf(
            'Roles, positions and expense categories are up to date for %d compan%s.',
            $companies->count(),
            $companies->count() === 1 ? 'y' : 'ies',
        ));
    }
}
