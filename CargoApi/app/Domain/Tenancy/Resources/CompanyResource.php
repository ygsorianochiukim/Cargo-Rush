<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Resources;

use App\Domain\Shared\Http\Resources\ApiResource;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\LogoStore;
use App\Domain\Tenancy\Support\RateBook;
use Illuminate\Http\Request;

/** @mixin Company */
class CompanyResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,

            'logo_url' => app(LogoStore::class)->url($this->logo_path),

            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'address' => $this->address,

            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'discoverable' => $this->isPinned(),

            'payroll_deduct_on' => $this->payroll_deduct_on?->value,
            'payroll_deduct_on_label' => $this->payroll_deduct_on?->label(),
            'payroll_deduct_on_detail' => $this->payroll_deduct_on?->detail(
                $this->payrollCalendar()->runsPerMonth(),
            ),

            'payroll_benefits_enabled' => $this->payroll_benefits_enabled ?? true,

            'payroll_cutoff_days' => $this->payroll_cutoff_days,

            'payroll_release_lag_days' => $this->payroll_release_lag_days,
            'payroll_calendar' => $this->payrollCalendar()->toArray(),

            'rates' => $this->rates()->inForce(),
            'rate_defaults' => $this->rates()->defaults(),
            'rate_overrides' => $this->rates()->overrides(),
        ];
    }

    private function rates(): RateBook
    {
        return app(RateBook::class)->for($this->resource);
    }
}
