<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CompanyService
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly LogoStore $logos,
    ) {}

    public function current(): Company
    {
        $company = $this->tenant->company();

        if ($company === null) {
            throw new RuntimeException('No company in force. This should have been caught by BindTenant.');
        }

        return $company;
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): Company
    {
        $company = $this->current();

        if (array_key_exists('payroll_pay_dates', $attributes)) {
            return DB::transaction(function () use ($company, $attributes): Company {
                $locked = Company::query()->lockForUpdate()->findOrFail($company->id);
                $changes = $attributes['payroll_pay_dates'];
                unset($attributes['payroll_pay_dates']);
                $locked->fill($attributes);
                $calendar = $locked->payrollCalendar();
                $dates = $locked->payroll_pay_dates ?? [];
                foreach ($changes as $cutoff => $payDate) {
                    if ($payDate === null) {
                        unset($dates[$cutoff]);

                        continue;
                    }

                    if (! Carbon::canBeCreatedFromFormat((string) $cutoff, 'Y-m-d')) {
                        throw ValidationException::withMessages(['payroll_pay_dates' => 'Choose a valid payroll cutoff.']);
                    }
                    $end = Carbon::parse($cutoff)->startOfDay();
                    $cutoffs = array_map(static fn ($period) => $period->end->toDateString(), $calendar->inMonth($end->year, $end->month));
                    if (! in_array($cutoff, $cutoffs, true) || $payDate < $cutoff || $payDate > $end->copy()->addDays(31)->toDateString()) {
                        throw ValidationException::withMessages(['payroll_pay_dates' => 'The pay date must be on or within 31 days after that cutoff.']);
                    }
                    $dates[$cutoff] = $payDate;
                }

                $locked->payroll_pay_dates = $dates ?: null;
                $locked->save();
                $this->tenant->set($locked);

                return $locked;
            });
        }

        $company->update($attributes);

        return $company->refresh();
    }

    public function setLogo(UploadedFile $file): Company
    {
        $company = $this->current();

        $company->update([
            'logo_path' => $this->logos->replace($company->logo_path, $file),
        ]);

        return $company->refresh();
    }

    public function clearLogo(): Company
    {
        $company = $this->current();
        $existing = $company->logo_path;

        $company->update(['logo_path' => null]);
        $this->logos->remove($existing);

        return $company->refresh();
    }
}
