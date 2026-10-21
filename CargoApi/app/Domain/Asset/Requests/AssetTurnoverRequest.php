<?php

declare(strict_types=1);

namespace App\Domain\Asset\Requests;

use App\Domain\Asset\Models\Asset;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;

class AssetTurnoverRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'to_employee_id' => [
                'nullable', 'string',
                Rule::exists('employees', 'id')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at'),
            ],
            'turned_over_on' => ['required', 'date', 'before_or_equal:today'],
            'condition' => ['sometimes', Rule::in(Asset::CONDITIONS)],
            'status' => ['sometimes', Rule::in(Asset::RETURN_STATUSES)],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'condition.in' => 'An asset is in good, fair or damaged condition.',
            'status.in' => 'A returned asset is available, under maintenance or inactive.',
        ];
    }
}
