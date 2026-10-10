<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Closure;
use RuntimeException;

trait SeedsIntoACompany
{
    /**
     * @template T
     *
     * @param Closure(Company):
     * @return T
     */
    protected function intoCompany(Closure $work): mixed
    {
        $company = $this->seedCompany();

        $this->command?->info("Seeding into {$company->name} ({$company->code}).");

        return app(Tenant::class)->use($company, static fn () => $work($company));
    }

    private function seedCompany(): Company
    {
        $inForce = app(Tenant::class)->company();

        if ($inForce !== null) {
            return $inForce;
        }

        $named = env('DEMO_COMPANY');

        if ($named !== null && $named !== '') {
            $company = Company::query()
                ->where('id', $named)
                ->orWhere('code', $named)
                ->orWhere('name', $named)
                ->first();

            if ($company === null) {
                throw new RuntimeException("No company matching DEMO_COMPANY=\"{$named}\".");
            }

            return $company;
        }

        $company = Company::query()->oldest()->first();

        if ($company === null) {
            throw new RuntimeException(
                'No companies on this install. Register one at POST /api/v1/register, then re-run.'
            );
        }

        return $company;
    }
}
