<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Console\Concerns;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Closure;

trait RunsPerCompany
{
    /**
     * @param Closure(Company):
     */
    protected function eachCompany(Closure $work): int
    {
        $companies = Company::query()->active()->orderBy('name')->get();

        if ($companies->isEmpty()) {
            $this->info('No active companies. Nothing to do.');

            return self::SUCCESS;
        }

        $tenant = app(Tenant::class);
        $failed = 0;

        foreach ($companies as $company) {
            if ($companies->count() > 1) {
                $this->line("<comment>{$company->name}</comment>");
            }

            try {
                $tenant->use($company, static fn () => $work($company));
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  {$company->name}: {$e->getMessage()}");
            }
        }

        if ($failed > 0) {
            $this->error("{$failed} of {$companies->count()} companies failed. The rest completed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
