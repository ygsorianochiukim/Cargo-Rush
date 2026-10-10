<?php

declare(strict_types=1);

namespace App\Domain\Trip\Requests;

use App\Domain\Shared\Http\Requests\ApiFormRequest;

class StartTripRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }
}
