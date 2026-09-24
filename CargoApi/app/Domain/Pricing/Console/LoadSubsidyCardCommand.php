<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Console;

use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Database\Seeders\SubsidyRateCardSeeder;
use Illuminate\Console\Command;

/**
 * Load the published subsidy table into one company's rate card.
 *
 * Deliberately **not** `RunsPerCompany`, which every other console command in
 * this system uses. Those sweep work that belongs to the platform and happens
 * to be done per firm — releasing due trips, chasing unpaid invoices. This
 * writes prices, and prices are one haulier's negotiated business with its
 * principal. A pass across every company on the install would hand all of them
 * somebody else's rate card, which is the one mistake in this module that
 * cannot be noticed by looking at a screen.
 *
 * So it names a company or refuses:
 *
 *     php artisan cargo:load-subsidy-card
 *     php artisan cargo:load-subsidy-card --company="Convenience Distribution"
 *
 * The figures live in `SubsidyRateCardSeeder`, which documents where they came
 * from. This is the tenancy around them.
 *
 * Safe to run again. Everything is keyed on the zone code and the class of
 * unit, so a second pass corrects the money in place rather than doubling the
 * card — which is what the next revision of the document will need, and which
 * matters because a rate line's id is on every trip it ever priced.
 */
class LoadSubsidyCardCommand extends Command
{
    protected $signature = 'cargo:load-subsidy-card
        {--company= : The company to load it into, by name or id}
        {--force : Skip the confirmation}';

    protected $description = 'Load the published subsidy table into a company’s rate card';

    public function handle(Tenant $tenant): int
    {
        $company = $this->company();

        if ($company === null) {
            return self::FAILURE;
        }

        $existing = $tenant->use($company, static fn (): int => PricingZone::query()->count());

        if ($existing > 0 && ! $this->option('force')) {
            $this->warn("{$company->name} already has {$existing} band(s) on its card.");
            $this->line('Bands matching a code in the table will be overwritten with the published');
            $this->line('figures. Bands with any other code are left alone.');

            if (! $this->confirm('Load the subsidy table?', false)) {
                $this->info('Nothing changed.');

                return self::SUCCESS;
            }
        }

        $tenant->use($company, function (): void {
            (new SubsidyRateCardSeeder)->setContainer(app())->run();
        });

        $bands = $tenant->use($company, static fn (): int => PricingZone::query()->count());

        $this->info("Loaded the subsidy table into {$company->name}. {$bands} band(s) on the card.");
        $this->line('Check a figure against the sheet: Rate Card → What would this cost?');

        return self::SUCCESS;
    }

    /**
     * The company to write to, or null with an explanation.
     *
     * An install with exactly one company needs no flag — that is the common
     * case, and asking a question with one possible answer is friction rather
     * than safety. More than one and it has to be named, because guessing
     * would put a rate card on a firm that never asked for it.
     */
    private function company(): ?Company
    {
        $named = (string) ($this->option('company') ?? '');

        if ($named !== '') {
            $company = Company::query()
                ->where('id', $named)
                ->orWhere('name', $named)
                ->first();

            if ($company === null) {
                $this->error("No company called \"{$named}\".");
                $this->listCompanies();
            }

            return $company;
        }

        $companies = Company::query()->orderBy('name')->get();

        if ($companies->count() === 1) {
            return $companies->first();
        }

        if ($companies->isEmpty()) {
            $this->error('There are no companies on this install yet.');

            return null;
        }

        $this->error('More than one company here, so say which with --company.');
        $this->listCompanies();

        return null;
    }

    private function listCompanies(): void
    {
        $this->newLine();
        $this->line('<comment>Companies on this install:</comment>');

        Company::query()->orderBy('name')->get()
            ->each(fn (Company $company) => $this->line("  {$company->name}"));
    }
}
