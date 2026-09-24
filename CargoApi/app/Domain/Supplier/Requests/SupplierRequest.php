<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Requests;

use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use App\Domain\Supplier\DTO\SupplierData;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Validation\Rule;

class SupplierRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();

        return [
            /**
             * One name per haulier.
             *
             * Scoped to the company and ignoring the row being edited, which
             * are the two halves a unique rule gets wrong: without the first,
             * two fleets cannot both buy from Shell; without the second,
             * saving a supplier without renaming it collides with itself.
             *
             * Soft-deleted rows are excluded, so a supplier the office removed
             * and later adds back is a new record rather than a 422 pointing at
             * something they cannot see.
             */
            'name' => [
                $required, 'string', 'max:120',
                Rule::unique('suppliers', 'name')
                    ->where('company_id', app(Tenant::class)->id())
                    ->whereNull('deleted_at')
                    ->ignore($this->route('supplier')),
            ],
            'contact' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:200'],
            'supplies' => ['nullable', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:255'],
            'status' => [
                'sometimes',
                Rule::in([StatusValue::Active->value, StatusValue::Inactive->value]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'You already buy from somebody by that name.',
            'status.in' => 'A supplier is active or inactive.',
        ];
    }

    public function toData(): SupplierData
    {
        return SupplierData::fromArray($this->validated());
    }
}
