<?php

declare(strict_types=1);

namespace App\Domain\Asset\Requests;

use App\Domain\Asset\DTO\AssetData;
use App\Domain\Asset\Models\Asset;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;

class AssetRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $company = app(Tenant::class)->id();

        $rules = [
            'name' => [$this->requiredOnCreate(), 'string', 'max:120'],
            'tag' => [
                'nullable', 'string', 'max:40',
                Rule::unique('assets', 'tag')
                    ->where('company_id', $company)
                    ->whereNull('deleted_at')
                    ->ignore($this->route('asset')),
            ],
            'category_id' => [
                'nullable', 'string',
                Rule::exists('asset_categories', 'id')->where('company_id', $company)->whereNull('deleted_at'),
            ],
            'serial_no' => ['nullable', 'string', 'max:80'],
            'purchased_on' => ['nullable', 'date', 'before_or_equal:today'],
            'cost_cents' => ['nullable', 'integer', 'min:0'],
            'supplier_id' => [
                'nullable', 'string',
                Rule::exists('suppliers', 'id')->where('company_id', $company)->whereNull('deleted_at'),
            ],
            'location' => ['nullable', 'string', 'max:120'],
            'condition' => ['sometimes', Rule::in(Asset::CONDITIONS)],
            'status' => ['sometimes', Rule::in(Asset::RETURN_STATUSES)],
            'note' => ['nullable', 'string', 'max:255'],
        ];

        if ($this->creating()) {
            $rules['custodian_id'] = [
                'nullable', 'string',
                Rule::exists('employees', 'id')->where('company_id', $company)->whereNull('deleted_at'),
            ];
            $rules['assigned_on'] = ['nullable', 'date', 'before_or_equal:today'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'tag.unique' => 'Another asset already carries that tag.',
            'cost_cents.integer' => 'Send the cost in centavos as a whole number, not pesos.',
            'condition.in' => 'An asset is in good, fair or damaged condition.',
            'status.in' => 'Set an asset available, under maintenance or inactive. Handing it to somebody is a turnover.',
        ];
    }

    public function toData(): AssetData
    {
        return AssetData::fromArray($this->validated());
    }
}
