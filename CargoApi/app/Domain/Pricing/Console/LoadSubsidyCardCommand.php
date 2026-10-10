<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Console;

use App\Domain\Pricing\Models\PricingZone;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Database\Seeders\SubsidyRateCardSeeder;
use Illuminate\Console\Command;

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
