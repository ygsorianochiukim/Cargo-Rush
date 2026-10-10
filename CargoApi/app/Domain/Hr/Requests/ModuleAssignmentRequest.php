<?php

declare(strict_types=1);

namespace App\Domain\Hr\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;

class ModuleAssignmentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'modules' => ['present', 'array', 'max:60'],
            'modules.*' => ['string', 'exists:nav_items,key'],
        ];
    }

    /** @return string[] */
    public function modules(): array
    {
        return array_values(array_filter((array) $this->input('modules', [])));
    }
}
