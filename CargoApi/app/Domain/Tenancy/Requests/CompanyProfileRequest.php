<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Requests;

use App\Domain\Payroll\Rules\PayrollCutoffDays;
use App\Domain\Shared\Enums\DeductionSchedule;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class CompanyProfileRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address' => ['sometimes', 'nullable', 'string', 'max:200'],

            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],

            'payroll_deduct_on' => ['sometimes', Rule::enum(DeductionSchedule::class)],

            'payroll_benefits_enabled' => ['sometimes', 'boolean'],

            'payroll_cutoff_days' => ['sometimes', 'nullable', 'array', new PayrollCutoffDays],

            'payroll_release_lag_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:14'],

            'trucker_commission_bp' => ['sometimes', 'integer', 'min:0', 'max:5000'],

            'billing_terms_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],

            'vat_registered' => ['sometimes', 'boolean'],
            'vat_rate_bp' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:5000'],
            'withholding_rate_bp' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:5000'],
            'prices_include_vat' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.required_with' => 'A longitude needs its latitude.',
            'longitude.required_with' => 'A latitude needs its longitude.',
            'payroll_deduct_on' => 'Contributions come off both cutoffs, the first, or the second.',
        ];
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        $attributes = $this->safe()->only([
            'contact_name', 'contact_email', 'contact_phone', 'address', 'latitude', 'longitude',
            'payroll_deduct_on', 'payroll_benefits_enabled', 'payroll_cutoff_days', 'payroll_release_lag_days',
            'trucker_commission_bp',
            'billing_terms_days',
            'vat_registered', 'vat_rate_bp', 'withholding_rate_bp', 'prices_include_vat',
        ]);

        foreach (['latitude', 'longitude'] as $key) {
            if (array_key_exists($key, $attributes) && $attributes[$key] !== null) {
                $attributes[$key] = (float) $attributes[$key];
            }
        }

        foreach ([
            'trucker_commission_bp',
            'billing_terms_days', 'vat_rate_bp', 'withholding_rate_bp', 'payroll_release_lag_days',
        ] as $key) {
            if (array_key_exists($key, $attributes) && $attributes[$key] !== null) {
                $attributes[$key] = (int) $attributes[$key];
            }
        }

        foreach (['vat_registered', 'prices_include_vat', 'payroll_benefits_enabled'] as $key) {
            if (array_key_exists($key, $attributes) && $attributes[$key] !== null) {
                $attributes[$key] = filter_var($attributes[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (array_key_exists('payroll_cutoff_days', $attributes)) {
            $days = array_values(array_unique(array_map('intval', (array) $attributes['payroll_cutoff_days'])));
            sort($days);

            $attributes['payroll_cutoff_days'] = $days === [] ? null : $days;
        }

        return $attributes;
    }
}
