<?php

declare(strict_types=1);

namespace App\Domain\Shared\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function creating(): bool
    {
        return $this->isMethod('POST');
    }

    protected function requiredOnCreate(): string
    {
        return $this->creating() ? 'required' : 'sometimes';
    }
}
