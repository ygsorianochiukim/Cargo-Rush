<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Closure;
use RuntimeException;

/**
 * Gives a seeder that writes business data a company to write it into.
 *
 * The demo seeders build a worked example — trucks, drivers, trips, a ledger.
 * Every one of those rows belongs to somebody now, and a seeder run from the
 * command line has no signed-in account to belong to. Without this they stop on
 * the first insert, which is the model layer refusing to create an unowned row
 * rather than a bug.
 *
 * `DEMO_COMPANY` names which one, by id, code or name. Left unset it is the
 * oldest company on the install, which on a single-company install is the only
 * one and means nobody has to think about it.
 *
 * Each demo seeder wraps its own `run()` rather than relying on `DemoSeeder` to
 * do it for all four, so any of them still works on its own:
 *
 *     php artisan db:seed --class="Database\Seeders\Demo\FleetSeeder"
 *
 * Nesting is free — `Tenant::use()` restores what it found — so a `DemoSeeder`
 * that has already entered the company loses nothing by each child entering it
 * again.
 */
trait SeedsIntoACompany
{
    /**
     * @template T
     *
     * @param  Closure(Company): T  $work
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
        /**
         * A company already in force wins, and nothing else is consulted.
         *
         * Somebody who wrapped this seeder in `Tenant::use()` — the
         * `cargo:demo-payroll` command does exactly that, after asking which
         * firm — has already answered the question. Re-deriving it from an
         * environment variable or from "the oldest company" quietly overrules
         * them, which is how a demo ends up in a company nobody is looking at
         * while the command cheerfully reports the one you picked.
         *
         * It also makes `DemoSeeder` calling four child seeders inside one
         * `intoCompany()` mean what it looks like it means.
         */
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

            // Thrown rather than falling back to the first company. An unset
            // DEMO_COMPANY means "wherever is sensible"; a mistyped one means
            // somebody had a specific company in mind, and quietly filling a
            // different one with demo trucks is the wrong way to be helpful.
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
