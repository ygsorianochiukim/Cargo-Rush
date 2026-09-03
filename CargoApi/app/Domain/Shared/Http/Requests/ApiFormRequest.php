<?php

declare(strict_types=1);

namespace App\Domain\Shared\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for every write request.
 *
 * One request class per module serves both store and update: `creating()`
 * makes the difference explicit, so the field list, its messages and its
 * payload builder are written once rather than diverging between two
 * near-identical classes.
 *
 * Authorization is the route middleware's job (`auth:sanctum` plus the
 * permission gate), so `authorize()` is true here and not quietly duplicated.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** POST is a create; PUT and PATCH are updates. */
    protected function creating(): bool
    {
        return $this->isMethod('POST');
    }

    /**
     * `required` when creating, `sometimes` when patching — so a PATCH can
     * send one field without having to resend the whole record.
     */
    protected function requiredOnCreate(): string
    {
        return $this->creating() ? 'required' : 'sometimes';
    }
}
